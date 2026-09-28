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
 * ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog, so the tool writes exactly what the
 * runtime reads and writes, including the adapter's safeguards around the library):
 *   - A POT template (<module>.pot, or the file name pattern without "%s", see --pattern)
 *   - One PO file per shipped language (<module>_<lang>.po, or named by --pattern)
 *
 * The entries have no msgctxt: a file belongs to exactly one module (the directory's prefix), and
 * without the module name in the file it stays valid when the module is renamed (e.g. a plugin that
 * becomes a component). The runtime still reads files with the module as msgctxt.
 *
 * No `.mo` file is written here - like the legacy `.lang` files before them, the `.po` files are the
 * shipped, version-controlled source of truth. The compiled `.mo` (the runtime format `ilLanguage`
 * actually reads) is only ever created in the per-installation overlay, when a language is installed
 * or updated - by `ILIAS\Language\Setup\LanguageInstallationManager` via
 * `MigratedLanguageFileSync::sync()`.
 *
 * Headers of an already existing target `.po` are kept, so re-running the tool for a module only
 * changes what the `.lang` files changed. The "Plural-Forms" header of every language comes from
 * plurals.json next to this tool (the canonical table, written into every generated `.po`).
 *
 * Plural messages (plurals.json, "modules"): the tool invents no text, it only distributes the
 * existing values - in a language whose rule is "n == 1 -> form 0, otherwise form 1", msgstr[0] is
 * the value of the configured singular key (if the language has it) and msgstr[1] the message's own
 * value; in every other language, and for a message without singular key, every form is the
 * message's own value. The msgid_plural is "<msgid>_plural" unless configured. The fuzzy flag
 * follows the message's own value - and the singular key's, where its value became msgstr[0] -, and
 * is also set wherever the own value was copied into more than one form (those forms still need a
 * translation; a language with a single form never copies). The singular key stays an entry of
 * its own.
 *
 * Deliberately declares no named classes or functions: this file lives inside the classmap-scanned
 * components/ tree, and anything named here would end up in Composer's autoload classmap (and be
 * included by Setup's class discovery). Everything is a local closure instead.
 *
 * Usage: php convert_module_to_po.php [--pattern=<pattern>] <module> [referenceLangKey] [outputDir]
 *   --pattern  file name of a language without ".po", "%s" = language key (default "<module>_%s");
 *              must match the pattern of the module's ComponentLanguageFileDirectory
 * Example: php convert_module_to_po.php tos de ../../../TermsOfService/lang
 */

// a build tool: never reachable through the web server
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 5) . '/vendor/composer/vendor/autoload.php';

use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\PluralForms;

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
 * The forms of the plural message $key in a language with the rule $plural_forms ($entries: the
 * language's entries of the module, see the file docblock).
 *
 * @param array<string, array{value: string, comment: ?string}> $entries
 * @param array{singular?: string, plural_id?: string} $definition
 * @return list<string>
 */
$takes_singular = static function (string $key, array $definition, array $entries, PluralForms $plural_forms): bool {
    $value = $entries[$key]['value'] ?? '';
    $singular = isset($definition['singular']) ? ($entries[$definition['singular']]['value'] ?? null) : null;

    return $value !== '' && $singular !== null && $singular !== '' && $plural_forms->isOneSingularOtherPlural();
};
$plural_forms_of = static function (string $key, array $definition, array $entries, PluralForms $plural_forms) use ($takes_singular): array {
    $value = $entries[$key]['value'] ?? '';
    if ($takes_singular($key, $definition, $entries, $plural_forms)) {
        return [$entries[$definition['singular']]['value'], $value];
    }

    return array_fill(0, $plural_forms->getCount(), $value);
};
/**
 * Whether the own value of the plural message $key was copied into more than one form (see the
 * file docblock) - such a message is flagged fuzzy.
 *
 * @param array<string, array{value: string, comment: ?string}> $entries
 * @param array{singular?: string, plural_id?: string} $definition
 */
$copies_own_value = static function (string $key, array $definition, array $entries, PluralForms $plural_forms) use ($takes_singular): bool {
    return ($entries[$key]['value'] ?? '') !== ''
        && $plural_forms->getCount() > 1
        && !$takes_singular($key, $definition, $entries, $plural_forms);
};

/**
 * @param array<array-key, array{value: string, comment: ?string}> $reference_entries msgid order + fallback extracted comments
 * @param array<string, array{value: string, comment: ?string}>|null $translation_entries null => POT (no msgstr, no fuzzy)
 * @param array<string, string> $existing_headers headers of an already existing target file
 * @param array<string, array{singular?: string, plural_id?: string}> $plural_definitions msgid => definition
 */
$build_catalog = static function (
    string $module,
    string $lang_key,
    array $reference_entries,
    ?array $translation_entries,
    array $existing_headers,
    ?PluralForms $plural_forms,
    array $plural_definitions
) use ($is_fuzzy_marker, $plural_forms_of, $copies_own_value): TranslationCatalog {
    $is_template = $translation_entries === null;
    $catalog = new TranslationCatalog();
    foreach ($existing_headers as $name => $value) {
        $catalog->setHeader($name, $value);
    }
    if (!$is_template && $plural_forms !== null) {
        $catalog->setHeader('Plural-Forms', $plural_forms->getHeader());
    }
    $catalog->setHeader('MIME-Version', '1.0');
    $catalog->setHeader('Content-Type', 'text/plain; charset=UTF-8');
    $catalog->setHeader('Content-Transfer-Encoding', '8bit');
    $catalog->setHeader('X-Domain', $module);
    if (!$is_template) {
        $catalog->setHeader('Language', $lang_key);
    }

    foreach ($reference_entries as $key => $ref) {
        // No msgctxt: the file belongs to one module (see the file docblock) - collisions between
        // modules stay excluded, since every module has its own file and the runtime assigns an
        // entry without msgctxt to the module of its directory
        $entry = new TranslationEntry(null, (string) $key);
        $comment = $ref['comment'];

        $plural_definition = $plural_definitions[(string) $key] ?? null;
        if ($plural_definition !== null) {
            $entry->setPlural(
                $plural_definition['plural_id'] ?? $key . '_plural',
                $is_template ? ['', ''] : $plural_forms_of((string) $key, $plural_definition, $translation_entries, $plural_forms)
            );
        }

        if (!$is_template) {
            $own = $translation_entries[$key] ?? null;
            if ($plural_definition === null) {
                $entry->translate($own['value'] ?? '');
            }
            // Deliberately no LocalChangeComments "original" comment: the shipped .po stays plain
            // translation content; "original" only exists in the per-installation overlay.
            $own_comment = $own['comment'] ?? null;
            if ($own_comment !== null && $is_fuzzy_marker($own_comment)) {
                $entry->addFlag('fuzzy');
            } elseif ($own_comment !== null) {
                $comment = $own_comment;
            }
            // msgstr[0] taken from a singular key that is not translated yet: the message is not either
            $singular_key = $plural_definition['singular'] ?? null;
            $singular_comment = $singular_key === null ? null : ($translation_entries[$singular_key]['comment'] ?? null);
            if (
                $singular_comment !== null
                && $is_fuzzy_marker($singular_comment)
                && $entry->getPluralTranslations()[0] !== ($own['value'] ?? '')
            ) {
                $entry->addFlag('fuzzy');
            }
            // the own value copied into several forms: they still need a translation
            if ($plural_definition !== null && $copies_own_value((string) $key, $plural_definition, $translation_entries, $plural_forms)) {
                $entry->addFlag('fuzzy');
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

$arguments = [];
$pattern = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--pattern=')) {
        $pattern = substr($argument, strlen('--pattern='));
        continue;
    }
    $arguments[] = $argument;
}
$module = $arguments[0] ?? 'tos';
$reference_lang_key = $arguments[1] ?? 'de';
$pattern ??= $module . '_%s';
try {
    MigratedLanguageFilePaths::assertValidShippedFileNamePattern($pattern);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$repo_root = dirname(__DIR__, 5);

// plurals.json: the Plural-Forms header of every language and the plural messages of each module
$plural_config_file = __DIR__ . '/plurals.json';
$plural_config = ['plural_forms' => [], 'modules' => []];
if (is_file($plural_config_file)) {
    try {
        $plural_config = json_decode((string) file_get_contents($plural_config_file), true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "FAILURE: $plural_config_file is no valid JSON: {$e->getMessage()}\n");
        exit(1);
    }
} else {
    echo "NOTE: no plurals.json next to the tool - no Plural-Forms headers and no plural messages are written.\n";
}
$plural_rules = [];
foreach ($plural_config['plural_forms'] ?? [] as $lang_key => $header) {
    try {
        $plural_rules[(string) $lang_key] = PluralForms::fromHeader((string) $header);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "FAILURE: invalid Plural-Forms of '$lang_key' in $plural_config_file: {$e->getMessage()}\n");
        exit(1);
    }
}
/** @var array<string, array{singular?: string, plural_id?: string}> $plural_definitions */
$plural_definitions = array_map(
    static fn(mixed $definition): array => is_array($definition) ? $definition : [],
    $plural_config['modules'][$module] ?? []
);
$output_dir = isset($arguments[2]) ? rtrim($arguments[2], '/') : (__DIR__ . '/output/' . $module);
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
    if (preg_match('/ilias_([a-z]+)\.lang\z/', $file, $m) !== 1) {
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

// A plural message and its singular key must exist, and every language needs its plural rule
$plural_problems = [];
foreach ($plural_definitions as $key => $definition) {
    if (!isset($reference_entries[$key])) {
        $plural_problems[] = "plural message '$key' is no key of the module";
    }
    if (isset($definition['singular']) && !isset($reference_entries[$definition['singular']])) {
        $plural_problems[] = "singular key '{$definition['singular']}' of '$key' is no key of the module";
    }
}
if ($plural_definitions !== []) {
    foreach (array_keys($per_language) as $lang_key) {
        if (!isset($plural_rules[$lang_key])) {
            $plural_problems[] = "no Plural-Forms for language '$lang_key'";
        }
    }
}
if ($plural_problems !== []) {
    fwrite(STDERR, "FAILURE: plurals.json does not fit module '$module' (nothing was written):\n  " . implode("\n  ", $plural_problems) . "\n");
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
$pot_name = trim(str_replace('%s', '', $pattern), '_-.');
$pot_path = $output_dir . '/' . ($pot_name === '' ? $module : $pot_name) . '.pot';
$write($pot_path, $build_catalog($module, '', $reference_entries, null, $existing_headers($pot_path), null, $plural_definitions)->toPoString());

$report = [];
foreach ($per_language as $lang_key => $entries) {
    $po_path = $output_dir . '/' . sprintf($pattern, $lang_key) . '.po';
    $write(
        $po_path,
        $build_catalog(
            $module,
            $lang_key,
            $reference_entries,
            $entries,
            $existing_headers($po_path),
            $plural_rules[$lang_key] ?? null,
            $plural_definitions
        )->toPoString()
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
        $parsed_entry = $parsed->find(null, $key);
        // find(null, ...) also finds msgctxt "" (same library id) - only an entry without msgctxt counts
        if ($parsed_entry?->getContext() !== null) {
            $po_mismatches[] = $key;
            continue;
        }
        $plural_definition = $plural_definitions[$key] ?? null;
        if ($plural_definition === null) {
            if ($parsed_entry?->isPlural() !== false || $parsed_entry->getTranslation() !== $expected) {
                $po_mismatches[] = $key;
            }
            continue;
        }
        // a plural message: its plural id and every form, each form one of the existing values
        $rule = $plural_rules[$lang_key];
        $expected_forms = $plural_forms_of($key, $plural_definition, $entries, $rule);
        $parsed_forms = array_pad(array_slice($parsed_entry?->getPluralTranslations() ?? [], 0, $rule->getCount()), $rule->getCount(), '');
        if (
            $parsed_entry?->getPluralId() !== ($plural_definition['plural_id'] ?? $key . '_plural')
            || count($expected_forms) !== $rule->getCount()
            || $parsed_forms !== $expected_forms
            || $rule->defaultValueOf($parsed_forms) !== $expected
        ) {
            $po_mismatches[] = $key . ' (plural)';
        }
    }
    foreach ($parsed->getEntries() as $parsed_entry) {
        if ($parsed_entry->getContext() !== null || !in_array($parsed_entry->getId(), $expected_keys, true)) {
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
