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
 * gettext/gettext library (through the language component's adapter
 * ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog, so the tool writes exactly what the
 * runtime reads and writes, including the adapter's safeguards around the library):
 *   - A POT template (<module>.pot)
 *   - One PO file per shipped language (<module>_<lang>.po)
 *
 * No `.mo` file is written here - like the legacy `.lang` files before them, the `.po` files are the
 * shipped, version-controlled source of truth. The compiled `.mo` (the runtime format `ilLanguage`
 * actually reads) is only ever created in the per-installation overlay, when a language is installed
 * or updated - by `ILIAS\Language\Setup\LanguageInstallationManager` via
 * `MigratedLanguageFileSync::sync()`.
 *
 * Headers of an already existing target `.po` (e.g., "Plural-Forms") are kept, so re-running the tool
 * for a module only changes what the `.lang` files changed.
 *
 * Deliberately declares no named classes or functions: this file lives inside the classmap-scanned
 * components/ tree, and anything named here would end up in Composer's autoload classmap (and be
 * included by Setup's class discovery). Everything is a local closure instead.
 *
 * Usage: php convert_module_to_po.php <module> [referenceLangKey] [outputDir]
 * Example: php convert_module_to_po.php tos de ../../../TermsOfService/lang
 */

// a build tool: never reachable through the web server
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 5) . '/vendor/composer/vendor/autoload.php';

use ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Gettext\TranslationEntry;

/**
 * @return list<array{0: string, 1: string, 2: string}> [module, key, raw value] of every entry line
 */
$read_lines = static function (string $file): array {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Cannot read $file");
    }
    $entries = [];
    foreach ($lines as $number => $line) {
        // ILIAS' own .lang reader trims every line - so must this one, or a value would keep
        // trailing whitespace / "\r" in the .po that the database never had
        $parts = explode('#:#', trim($line));
        if (count($parts) < 3) {
            continue;
        }
        if (count($parts) > 3) {
            // Parsed exactly like LanguageInstallationManager::insertLanguage(): the value is the
            // third part, everything after a further "#:#" is not part of it - reported, since
            // that is most likely not what the file's author intended
            fwrite(STDERR, sprintf(
                "WARNING: %s:%d contains \"#:#\" in its value - only the part before it is used, like the installer does.\n",
                $file,
                $number + 1
            ));
        }
        $entries[] = [$parts[0], $parts[1], $parts[2]];
    }

    return $entries;
};

/**
 * @return array<string, array{value: string, comment: ?string}>
 */
$parse_module = static function (string $file, string $module) use ($read_lines): array {
    $entries = [];
    foreach ($read_lines($file) as [$mod, $key, $raw_value]) {
        if ($mod !== $module) {
            continue;
        }
        $comment = null;
        $value = $raw_value;
        $comment_pos = strpos($raw_value, '###');
        if ($comment_pos !== false) {
            $value = substr($raw_value, 0, $comment_pos);
            $comment = trim(substr($raw_value, $comment_pos + 3));
        }
        $entries[$key] = ['value' => $value, 'comment' => $comment];
    }

    return $entries;
};

/**
 * Build-time uniqueness validation (FR "PO-Files for improving language handling", 2.4): a legacy
 * `.lang` file can define the same identifier twice for one module.
 *
 * @return list<string> identifiers that occur more than once for $module in $file
 */
$find_duplicate_keys = static function (string $file, string $module) use ($read_lines): array {
    $seen = [];
    $duplicates = [];
    foreach ($read_lines($file) as [$mod, $key]) {
        if ($mod !== $module) {
            continue;
        }
        if (isset($seen[$key])) {
            $duplicates[$key] = true;
        }
        $seen[$key] = true;
    }

    return array_keys($duplicates);
};

/**
 * Classifies a legacy `###`-comment as either a real developer/translator note (-> PO "#."
 * extracted comment) or an auto-generated "not translated yet" placeholder marker (-> PO
 * "#, fuzzy" flag). In most non-source languages, > 99% of comments are dated "new variable"
 * markers, not authored notes. Only that dated marker counts - an authored note merely
 * mentioning "new variable" stays a note.
 */
$is_fuzzy_marker = static function (string $comment): bool {
    return preg_match(
        '/^\s*(\d{1,2}\s+\d{1,2}\s+\d{4}|\d{1,2}\s+[A-Za-z]{3}\s+\d{4}|\d{4}-\d{1,2}-\d{1,2})\b.*\bnew variable\b/i',
        $comment
    ) === 1;
};

/**
 * @param array<array-key, array{value: string, comment: ?string}> $reference_entries msgid order + fallback extracted comments
 * @param array<string, array{value: string, comment: ?string}>|null $translation_entries null => POT (no msgstr, no fuzzy)
 * @param array<string, string> $existing_headers headers of an already existing target file
 */
$build_catalog = static function (
    string $module,
    string $lang_key,
    array $reference_entries,
    ?array $translation_entries,
    array $existing_headers
) use ($is_fuzzy_marker): TranslationCatalog {
    $is_template = $translation_entries === null;
    $catalog = new TranslationCatalog();
    foreach ($existing_headers as $name => $value) {
        $catalog->setHeader($name, $value);
    }
    $catalog->setHeader('MIME-Version', '1.0');
    $catalog->setHeader('Content-Type', 'text/plain; charset=UTF-8');
    $catalog->setHeader('Content-Transfer-Encoding', '8bit');
    $catalog->setHeader('X-Domain', $module);
    if (!$is_template) {
        $catalog->setHeader('Language', $lang_key);
    }

    foreach ($reference_entries as $key => $ref) {
        // msgctxt = owning module (FR "PO-Files for improving language handling", 2.4, Variant A):
        // structurally excludes collisions between modules that happen to pick the same identifier.
        $entry = new TranslationEntry($module, (string) $key);
        $comment = $ref['comment'];

        if (!$is_template) {
            $own = $translation_entries[$key] ?? null;
            $entry->translate($own['value'] ?? '');
            // Deliberately no LocalChangeComments "original" comment: the shipped .po stays plain
            // translation content; "original" only exists in the per-installation overlay.
            $own_comment = $own['comment'] ?? null;
            if ($own_comment !== null && $is_fuzzy_marker($own_comment)) {
                $entry->addFlag('fuzzy');
            } elseif ($own_comment !== null) {
                $comment = $own_comment;
            }
        }

        if ($comment !== null && $comment !== '' && !$is_fuzzy_marker($comment)) {
            $entry->addExtractedComment($comment);
        }

        $catalog->add($entry);
    }

    return $catalog;
};

$existing_headers = static function (string $file): array {
    try {
        return is_file($file) ? TranslationCatalog::fromPoFile($file)->getHeaders() : [];
    } catch (RuntimeException) {
        return [];
    }
};

$write = static function (string $file, string $content): void {
    if (file_put_contents($file, $content) === false) {
        throw new RuntimeException("Cannot write $file");
    }
};

// --- main ---
//
// outputDir defaults to this tool's own output/<module> scratch folder. For a module whose owning
// component already contributes a ComponentLanguageFileDirectory (see
// components/ILIAS/Language/src/ComponentTranslation/), pass that component's lang/ directory
// explicitly instead - e.g., for "tos":
//   php convert_module_to_po.php tos de ../../../TermsOfService/lang

$module = $argv[1] ?? 'tos';
$reference_lang_key = $argv[2] ?? 'de';

$repo_root = dirname(__DIR__, 5);
$output_dir = isset($argv[3]) ? rtrim($argv[3], '/') : (__DIR__ . '/output/' . $module);
if (!is_dir($output_dir) && !mkdir($output_dir, 0775, true) && !is_dir($output_dir)) {
    throw new RuntimeException("Cannot create $output_dir");
}

$lang_files = glob($repo_root . '/lang/ilias_*.lang');
sort($lang_files);
if ($lang_files === []) {
    fwrite(STDERR, "No lang files found under $repo_root/lang\n");
    exit(1);
}

$per_language = [];
$duplicate_report = [];
foreach ($lang_files as $file) {
    if (preg_match('/ilias_([a-z]+)\.lang$/', $file, $m) !== 1) {
        fwrite(STDERR, "WARNING: skipping $file - no language key in its name.\n");
        continue;
    }
    $lang_key = $m[1];
    $per_language[$lang_key] = $parse_module($file, $module);

    $duplicates = $find_duplicate_keys($file, $module);
    if ($duplicates !== []) {
        $duplicate_report[$lang_key] = $duplicates;
    }
}

// fail before writing anything rather than silently keeping one of the occurrences
if ($duplicate_report !== []) {
    fwrite(STDERR, "FAILURE: module '$module' has duplicate identifiers in the source .lang files:\n");
    foreach ($duplicate_report as $lang_key => $duplicates) {
        fwrite(STDERR, "  $lang_key: " . implode(', ', $duplicates) . "\n");
    }
    exit(1);
}

if (!isset($per_language[$reference_lang_key])) {
    fwrite(STDERR, "Reference language '$reference_lang_key' not found.\n");
    exit(1);
}
$reference_entries = $per_language[$reference_lang_key];
if ($reference_entries === []) {
    fwrite(STDERR, "Module '$module' has no entries in reference language '$reference_lang_key'.\n");
    exit(1);
}

// Every language's keys must be a subset of the reference language's: the catalogs are built from
// the reference keys, so a key only another language has would otherwise be dropped silently
$keys_missing_in_reference = [];
foreach ($per_language as $lang_key => $entries) {
    $extra = array_diff(array_map('strval', array_keys($entries)), array_map('strval', array_keys($reference_entries)));
    if ($extra !== []) {
        $keys_missing_in_reference[$lang_key] = $extra;
    }
}
if ($keys_missing_in_reference !== []) {
    fwrite(STDERR, "FAILURE: module '$module' has keys that the reference language '$reference_lang_key' does not have (nothing was written):\n");
    foreach ($keys_missing_in_reference as $lang_key => $keys) {
        fwrite(STDERR, "  $lang_key: " . implode(', ', $keys) . "\n");
    }
    exit(1);
}

// POT
$pot_path = "$output_dir/$module.pot";
$write($pot_path, $build_catalog($module, '', $reference_entries, null, $existing_headers($pot_path))->toPoString());

$report = [];
foreach ($per_language as $lang_key => $entries) {
    $po_path = $output_dir . '/' . $module . '_' . $lang_key . '.po';
    $write(
        $po_path,
        $build_catalog($module, $lang_key, $reference_entries, $entries, $existing_headers($po_path))->toPoString()
    );

    // self-check: read the written file back and compare every value - of every reference key and
    // of every key of this language - and that the file holds nothing else
    $parsed = TranslationCatalog::fromPoFile($po_path);
    $po_mismatches = [];
    $expected_keys = array_unique(array_merge(
        array_map('strval', array_keys($reference_entries)),
        array_map('strval', array_keys($entries))
    ));
    foreach ($expected_keys as $key) {
        $expected = $entries[$key]['value'] ?? '';
        if ($parsed->find($module, $key)?->getTranslation() !== $expected) {
            $po_mismatches[] = $key;
        }
    }
    foreach ($parsed->getEntries() as $parsed_entry) {
        if ($parsed_entry->getContext() !== $module || !in_array($parsed_entry->getId(), $expected_keys, true)) {
            $po_mismatches[] = '(unexpected) ' . $parsed_entry->getId();
        }
    }

    $fuzzy_count = 0;
    foreach ($entries as $e) {
        if ($e['comment'] !== null && $is_fuzzy_marker($e['comment'])) {
            $fuzzy_count++;
        }
    }

    $report[] = [
        'lang' => $lang_key,
        'entries' => count($entries),
        'fuzzy' => $fuzzy_count,
        'po_ok' => $po_mismatches === [],
        'po_mismatches' => $po_mismatches,
    ];
}

printf("%-6s %8s %8s %8s\n", 'lang', 'entries', 'fuzzy', 'po_ok');
$all_ok = true;
foreach ($report as $row) {
    printf("%-6s %8d %8d %8s\n", $row['lang'], $row['entries'], $row['fuzzy'], $row['po_ok'] ? 'yes' : 'NO');
    if (!$row['po_ok']) {
        $all_ok = false;
        fwrite(STDERR, "  po_mismatches: " . implode(', ', $row['po_mismatches']) . "\n");
    }
}

echo "\n";
echo $all_ok
    ? "OK: all " . count($report) . " languages round-trip identically through PO.\n"
    : "FAILURE: at least one language did not round-trip correctly, see stderr above.\n";

exit($all_ok ? 0 : 1);
