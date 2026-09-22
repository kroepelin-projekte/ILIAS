<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

/**
 * Pilot conversion tool for the PO/MO migration.
 *
 * Reads the legacy `lang/ilias_<lang>.lang` files for one ILIAS language module and emits, via the
 * `gettext/gettext` + `gettext/languages` Composer packages already vendored in this project:
 *   - a POT template (<module>.pot)
 *   - one PO file per shipped language (<module>_<lang>.po)
 *
 * No `.mo` file is written here - like the legacy `.lang` files before them, the `.po` files are the
 * shipped, version-controlled source of truth. The compiled `.mo` (the runtime format `ilLanguage`
 * actually reads) is only ever created later, when a language is installed - by
 * `ILIAS\Language\Setup\LanguageInstallationManager` via `MigratedLanguageFileSync::sync()`, run
 * either from ILIAS Setup (e.g. installing `en`) or from the language administration GUI.
 *
 * Usage: php convert_module_to_po.php <module> [referenceLangKey] [outputDir]
 * Example: php convert_module_to_po.php tos de ../../TermsOfService/lang
 */

require dirname(__DIR__, 5) . '/vendor/composer/vendor/autoload.php';

use Gettext\Translation;
use Gettext\Translations;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use ILIAS\Language\ComponentTranslation\LocalChangeComments;

final class LegacyLangFileParser
{
    /**
     * @return array<string, array{value: string, comment: ?string}>
     */
    public static function parseModule(string $file, string $module): array
    {
        $entries = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read $file");
        }

        foreach ($lines as $line) {
            $parts = explode('#:#', $line);
            if (count($parts) !== 3) {
                continue;
            }
            [$mod, $key, $rawValue] = $parts;
            if ($mod !== $module) {
                continue;
            }

            $comment = null;
            $commentPos = strpos($rawValue, '###');
            $value = $rawValue;
            if ($commentPos !== false) {
                $value = substr($rawValue, 0, $commentPos);
                $comment = trim(substr($rawValue, $commentPos + 3));
            }

            $entries[$key] = ['value' => $value, 'comment' => $comment];
        }

        return $entries;
    }

    /**
     * Build-time uniqueness validation (see FR "PO-Files for improving language handling", 2.4):
     * a legacy `.lang` file can - by construction of the module#:#key#:#value format - define the
     * same identifier twice for one module. parseModule() silently keeps only the last occurrence
     * (plain PHP array key overwrite), exactly the "silently overwriting" behavior the FR wants
     * replaced by a reported validation error. This does the detection parseModule() itself doesn't.
     *
     * @return string[] identifiers that occur more than once for $module in $file
     */
    public static function findDuplicateKeys(string $file, string $module): array
    {
        $seen = [];
        $duplicates = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read $file");
        }

        foreach ($lines as $line) {
            $parts = explode('#:#', $line);
            if (count($parts) !== 3 || $parts[0] !== $module) {
                continue;
            }
            $key = $parts[1];
            if (isset($seen[$key])) {
                $duplicates[$key] = true;
            }
            $seen[$key] = true;
        }

        return array_keys($duplicates);
    }
}

/**
 * Classifies a legacy `###`-comment as either a real developer/translator note (-> PO
 * "#." extracted comment) or an auto-generated "not translated yet" placeholder marker
 * (-> PO "#, fuzzy" flag). In most non-source languages, > 99% of comments are dated
 * "new variable" markers, not authored notes.
 */
final class CommentClassifier
{
    private const string FUZZY_MARKER_PATTERN =
        '/^\s*(\d{1,2}\s+\d{1,2}\s+\d{4}|\d{1,2}\s+[A-Za-z]{3}\s+\d{4}|\d{4}-\d{1,2}-\d{1,2})\b.*\bnew variable\b/i';
    private const string FUZZY_GENERIC_PATTERN = '/\bnew variable\b|\badd new translation\b/i';

    public static function isFuzzyMarker(string $comment): bool
    {
        return (bool) preg_match(self::FUZZY_MARKER_PATTERN, $comment)
            || (bool) preg_match(self::FUZZY_GENERIC_PATTERN, $comment);
    }
}

/**
 * @param array<string, array{value: string, comment: ?string}> $referenceEntries msgid order + fallback extracted comments
 * @param array<string, array{value: string, comment: ?string}>|null $translationEntries null => POT (no msgstr, no fuzzy)
 */
function buildTranslations(
    string $module,
    string $langKey,
    array $referenceEntries,
    ?array $translationEntries
): Translations {
    $isTemplate = $translationEntries === null;
    $translations = $isTemplate
        ? Translations::create($module)
        : Translations::create($module, $langKey);

    $translations->getHeaders()
        ->set('MIME-Version', '1.0')
        ->set('Content-Type', 'text/plain; charset=UTF-8')
        ->set('Content-Transfer-Encoding', '8bit');

    foreach ($referenceEntries as $key => $ref) {
        // msgctxt = owning module (FR "PO-Files for improving language handling", 2.4, Variant A):
        // structurally excludes collisions between modules that happen to pick the same identifier,
        // instead of the flat "last loaded module wins" merge the legacy DB-backed scheme has today.
        $translation = Translation::create($module, $key);
        $comment = $ref['comment'];

        if (!$isTemplate) {
            $own = $translationEntries[$key] ?? null;
            $translation->translate($own['value'] ?? '');
            // The value this entry shipped with at migration time (see LocalChangeComments) - written
            // once, here, and never touched again; ilObjLanguage::syncMigratedLanguageFile() diffs
            // future writes against it to decide whether an entry counts as locally changed.
            LocalChangeComments::setOriginal($translation, $own['value'] ?? '');
            $ownComment = $own['comment'] ?? null;

            if ($ownComment !== null && CommentClassifier::isFuzzyMarker($ownComment)) {
                $translation->getFlags()->add('fuzzy');
            } elseif ($ownComment !== null) {
                $comment = $ownComment;
            }
        }

        if ($comment !== null && !CommentClassifier::isFuzzyMarker($comment)) {
            $translation->getExtractedComments()->add($comment);
        }

        $translations->add($translation);
    }

    return $translations;
}

// --- main ---
//
// Usage: php convert_module_to_po.php <module> [referenceLangKey] [outputDir]
//
// outputDir defaults to this tool's own output/<module> scratch folder. For a module whose
// owning component already contributes a ComponentLanguageFileDirectory (see
// components/ILIAS/Language/src/ComponentTranslation/), pass that component's lang/ directory
// explicitly instead - e.g. for "tos":
//   php convert_module_to_po.php tos de ../../../TermsOfService/lang
//
// Guarded so this only runs when the file is the actual CLI entry point, not merely declared: this
// script's top-level classes (LegacyLangFileParser, CommentClassifier) end up in Composer's
// generated autoload classmap like any other top-level class in this tree, and ILIAS's own Setup
// pipeline (ImplementationOfInterfaceFinder, used by e.g. the Export component's
// "Build export_artifact Artifact" objective) reflects on every classmapped class during
// `composer install`, which forces the autoloader to include this whole file. Without this guard
// that also re-runs the block below - including its exit() call - and kills that unrelated Setup
// process outright. $argv[0] is the true entry script's path regardless of what gets include()'d
// along the way, so this stays false whenever the file is loaded for its classes only.
if (realpath($argv[0] ?? '') !== __FILE__) {
    return;
}

$module = $argv[1] ?? 'tos';
$referenceLangKey = $argv[2] ?? 'de';

$repoRoot = dirname(__DIR__, 5);
$outputDir = isset($argv[3]) ? rtrim($argv[3], '/') : (__DIR__ . '/output/' . $module);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException("Cannot create $outputDir");
}

$langFiles = glob($repoRoot . '/lang/ilias_*.lang');
sort($langFiles);
if ($langFiles === []) {
    fwrite(STDERR, "No lang files found under $repoRoot/lang\n");
    exit(1);
}

$perLanguage = [];
$duplicateReport = [];
foreach ($langFiles as $file) {
    preg_match('/ilias_([a-z]+)\.lang$/', $file, $m);
    $langKey = $m[1];
    $perLanguage[$langKey] = LegacyLangFileParser::parseModule($file, $module);

    $duplicates = LegacyLangFileParser::findDuplicateKeys($file, $module);
    if ($duplicates !== []) {
        $duplicateReport[$langKey] = $duplicates;
    }
}

// Build-time uniqueness validation (FR "PO-Files for improving language handling", 2.4): fail
// before writing anything rather than silently keeping whichever occurrence parseModule() saw last.
if ($duplicateReport !== []) {
    fwrite(STDERR, "FAILURE: module '$module' has duplicate identifiers in the source .lang files:\n");
    foreach ($duplicateReport as $langKey => $duplicates) {
        fwrite(STDERR, "  $langKey: " . implode(', ', $duplicates) . "\n");
    }
    exit(1);
}

if (!isset($perLanguage[$referenceLangKey])) {
    fwrite(STDERR, "Reference language '$referenceLangKey' not found.\n");
    exit(1);
}
$referenceEntries = $perLanguage[$referenceLangKey];
if ($referenceEntries === []) {
    fwrite(STDERR, "Module '$module' has no entries in reference language '$referenceLangKey'.\n");
    exit(1);
}

$poGenerator = new PoGenerator();
$poLoader = new PoLoader();

// POT
$pot = buildTranslations($module, '', $referenceEntries, null);
$poGenerator->generateFile($pot, "$outputDir/$module.pot");

$report = [];
foreach ($perLanguage as $langKey => $entries) {
    $translations = buildTranslations($module, $langKey, $referenceEntries, $entries);

    $poPath = "$outputDir/{$module}_{$langKey}.po";
    $poGenerator->generateFile($translations, $poPath);

    $catalog = [];
    foreach ($referenceEntries as $key => $ref) {
        $catalog[$key] = $entries[$key]['value'] ?? '';
    }

    // self-check: PO round-trip, read back through the same library (context = module, see above)
    $poParsed = $poLoader->loadFile($poPath);
    $poMismatches = [];
    foreach ($catalog as $key => $expected) {
        $t = $poParsed->find($module, $key);
        if (($t?->getTranslation() ?? null) !== $expected) {
            $poMismatches[] = $key;
        }
    }

    $fuzzyCount = 0;
    foreach ($entries as $e) {
        if ($e['comment'] !== null && CommentClassifier::isFuzzyMarker($e['comment'])) {
            $fuzzyCount++;
        }
    }

    $report[] = [
        'lang' => $langKey,
        'entries' => count($entries),
        'fuzzy' => $fuzzyCount,
        'po_ok' => $poMismatches === [],
        'po_mismatches' => $poMismatches,
    ];
}

printf("%-6s %8s %8s %8s\n", 'lang', 'entries', 'fuzzy', 'po_ok');
$allOk = true;
foreach ($report as $row) {
    printf(
        "%-6s %8d %8d %8s\n",
        $row['lang'],
        $row['entries'],
        $row['fuzzy'],
        $row['po_ok'] ? 'yes' : 'NO'
    );
    if (!$row['po_ok']) {
        $allOk = false;
        fwrite(STDERR, "  po_mismatches: " . implode(', ', $row['po_mismatches']) . "\n");
    }
}

echo "\n";
echo $allOk
    ? "OK: all " . count($report) . " languages round-trip byte-identical through PO.\n"
    : "FAILURE: at least one language did not round-trip correctly, see stderr above.\n";

exit($allOk ? 0 : 1);
