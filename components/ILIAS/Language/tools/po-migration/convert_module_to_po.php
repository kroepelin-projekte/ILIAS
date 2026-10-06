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
 * plurals.json next to this tool (the canonical table, written into every generated `.po`). The
 * "Language" header keeps the language key, which translation tools read; the "Language-Team"
 * header names the language in English, taken from `meta_l_<lang>` of `lang/ilias_en.lang` - or,
 * once the module "meta" has no lines there any more (migrated), from `meta_l_<lang>` of a shipped
 * `components/<Vendor>/<Component>/lang/meta_en.po` (default file name pattern only).
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
 * Usage: php convert_module_to_po.php [--pattern=<pattern>] [--skip-unmigratable-keys] [--remove-from-lang]
 *          <module> [referenceLangKey] [outputDir]
 *   --pattern  file name of a language without ".po", "%s" = language key (default "<module>_%s");
 *              must match the pattern of the module's ComponentLanguageFileDirectory
 *   --skip-unmigratable-keys  instead of failing: leave out (with a WARNING on stderr) every key the
 *              reference language does not have and the empty key, and use one occurrence of a key
 *              that occurs more than once in a language with the same value and comment every time,
 *              as the installer reads them (other duplicates of a key left out anyway only warn,
 *              all others still fail); the left out keys are not part of the round-trip check
 *   --remove-from-lang  only after a completely successful run (every file written, every language
 *              round-tripped): remove every line of the module - the left out ones included - from
 *              every lang/ilias_<lang>.lang read (atomically per file, all other bytes unchanged);
 *              if one file cannot be processed, no file is changed. Requires (checked first) an
 *              existing outputDir components/<Vendor>/<Component>/lang whose <Component>.php already
 *              contributes the module's ComponentLanguageFileDirectory, and no symbolic link in lang/
 * Unknown options (anything starting with "-") fail; module and reference language must match
 * [A-Za-z0-9_]+. A module without any line in lang/ fails without writing anything (most likely
 * migrated already).
 * Example: php convert_module_to_po.php tos de ../../../TermsOfService/lang
 */

// a build tool: never reachable through the web server
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 5) . '/vendor/composer/vendor/autoload.php';

use ILIAS\Language\ComponentTranslation\AtomicFileWriter;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\PluralForms;

/**
 * $text with every control character (e.g. ESC of a terminal escape sequence) made visible - for
 * everything the tool prints that comes from a `.lang` file or the command line.
 */
$printable = static function (string $text): string {
    return addcslashes($text, "\0..\37\177");
};

/**
 * The content of $file - read once, so the lines and the hash checked by --remove-from-lang are
 * taken from the same bytes.
 */
$read_file = static function (string $file): string {
    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException("Cannot read $file");
    }

    return $content;
};

/**
 * @param string $content of the `.lang` file $file (only named in warnings)
 * @return list<array{0: string, 1: string, 2: string}> [module, key, raw value] of every entry line
 */
$read_lines = static function (string $file, string $content): array {
    $entries = [];
    // the lines file() would give: split at "\n" only (a "\r" before it is trimmed below)
    foreach (explode("\n", $content) as $number => $line) {
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
 * @return list<array{0: string, 1: string}> [key, raw value] of every line of $module in $content
 *         (of $file), in file order
 */
$module_lines = static function (string $file, string $content, string $module) use ($read_lines): array {
    $lines = [];
    foreach ($read_lines($file, $content) as [$mod, $key, $raw_value]) {
        if ($mod === $module) {
            $lines[] = [$key, $raw_value];
        }
    }

    return $lines;
};

/**
 * @param list<array{0: string, 1: string}> $lines see $module_lines - a later line of the same key wins
 * @return array<string, array{value: string, comment: ?string}>
 */
$entries_of = static function (array $lines): array {
    $entries = [];
    foreach ($lines as [$key, $raw_value]) {
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
 * @return array<string, array{value: string, comment: ?string}>
 */
$parse_module = static function (string $file, string $module) use ($read_file, $module_lines, $entries_of): array {
    return $entries_of($module_lines($file, $read_file($file), $module));
};

/**
 * Build-time uniqueness validation (FR "PO-Files for improving language handling", 2.4): a legacy
 * `.lang` file can define the same identifier twice for one module.
 *
 * @param list<array{0: string, 1: string}> $lines see $module_lines
 * @return array<string, bool> identifier occurring more than once => whether all its occurrences have
 *         the same value and the same "###" comment, as the installer reads them (the third "#:#"
 *         part of the trimmed line - so occurrences that only differ in surrounding whitespace or
 *         in a part after a further "#:#" count as the same)
 */
$find_duplicate_keys = static function (array $lines): array {
    $raw_values = [];
    foreach ($lines as [$key, $raw_value]) {
        $raw_values[$key][] = $raw_value;
    }
    $duplicates = [];
    foreach ($raw_values as $key => $values) {
        if (count($values) > 1) {
            $duplicates[(string) $key] = count(array_unique($values)) === 1;
        }
    }

    return $duplicates;
};

/**
 * Keys as the reports list them: comma separated, the empty key and control characters made visible.
 *
 * @param array<array-key> $keys
 */
$format_keys = static function (array $keys) use ($printable): string {
    return implode(', ', array_map(
        static fn(int|string $key): string => (string) $key === '' ? '"" (empty key)' : $printable((string) $key),
        $keys
    ));
};

/**
 * $content (a legacy `.lang` file) without the lines of $module - exactly the lines $read_lines
 * reports for it (trimmed, split at "#:#", at least three parts), and only behind the line
 * "<!-- language file start -->" (the installer ignores everything before it; a file without that
 * line counts as body only). Every other byte stays as it is: header, comments, line endings, order,
 * and whether the file ends with a line break.
 *
 * @return array{0: string, 1: int} the new content and the number of removed lines
 */
$without_module_lines = static function (string $content, string $module): array {
    $lines = preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY);
    $in_body = true;
    foreach ($lines as $line) {
        if (trim($line) === '<!-- language file start -->') {
            $in_body = false;
            break;
        }
    }
    $kept = [];
    $removed = 0;
    foreach ($lines as $line) {
        if (!$in_body) {
            $in_body = trim($line) === '<!-- language file start -->';
            $kept[] = $line;
            continue;
        }
        $parts = explode('#:#', trim($line));
        if (count($parts) >= 3 && $parts[0] === $module) {
            $removed++;
            continue;
        }
        $kept[] = $line;
    }
    $new_content = implode('', $kept);
    // the removed line was the last one, without a line break: the new last line loses its own
    if ($removed > 0 && !str_ends_with($content, "\n") && str_ends_with($new_content, "\n")) {
        $new_content = substr($new_content, 0, str_ends_with($new_content, "\r\n") ? -2 : -1);
    }

    return [$new_content, $removed];
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
 * @param string|null $language_name English name of the language, null => keep an existing "Language-Team"
 */
$build_catalog = static function (
    string $module,
    string $lang_key,
    array $reference_entries,
    ?array $translation_entries,
    array $existing_headers,
    ?PluralForms $plural_forms,
    array $plural_definitions,
    ?string $language_name = null
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
    if (!$is_template && $language_name !== null) {
        $catalog->setHeader('Language-Team', $language_name);
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

/**
 * Why $output_argument is no place --remove-from-lang may rely on, or null: it must be given, exist
 * already and be the "lang" directory of a component of this repository
 * (components/<Vendor>/<Component>/lang), whose component class <Component>.php contributes a
 * ComponentLanguageFileDirectory for $module there with the file name pattern $pattern - otherwise the
 * installation would not find the written .po files, and the module would lose its values.
 *
 * A text check without bootstrap: it recognises `new ComponentLanguageFileDirectory($this, '<module>'
 * [, '<path inside the component>'[, '<pattern>']])` with string literals as positional arguments
 * inside an assignment to `$contribute[LanguageFileDirectory::class]` (comments ignored). Any other
 * form (named arguments, constants, a variable) is not recognised and refused.
 */
$removal_target_problem = static function (string $repo_root, ?string $output_argument, string $module, string $pattern) use ($printable): ?string {
    if ($output_argument === null) {
        return 'the outputDir argument is required - the existing lang/ directory of the component that contributes the module.';
    }
    $resolved = realpath($output_argument);
    if ($resolved === false || !is_dir($resolved)) {
        return "outputDir '" . $printable($output_argument) . "' does not exist - the component's lang/ directory must exist before the run.";
    }
    $components = realpath($repo_root . '/components');
    if (
        $components === false
        || preg_match('#\A' . preg_quote($components, '#') . '/([^/]+)/([^/]+)/lang\z#', $resolved, $m) !== 1
    ) {
        return "outputDir '" . $printable($output_argument) . "' is not components/<Vendor>/<Component>/lang of this repository.";
    }
    $component_file = "$components/{$m[1]}/{$m[2]}/{$m[2]}.php";
    $source = is_file($component_file) ? file_get_contents($component_file) : false;
    if ($source === false) {
        return "the component class $component_file was not found.";
    }
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= is_array($token) ? $token[1] : $token;
        }
    }
    $class = '(?:\\\\?(?:\w+\\\\)*)?';
    $string = static fn(int $quote, int $text): string => "(['\"])(?<t$text>[^'\"]*)\\$quote";
    preg_match_all(
        '/\$contribute\s*\[\s*' . $class . 'LanguageFileDirectory::class\s*\]\s*=[^;]*?new\s+' . $class
        . 'ComponentLanguageFileDirectory\s*\(\s*\$this\s*,\s*' . $string(1, 1)
        . '\s*(?:,\s*' . $string(3, 2) . '\s*)?(?:,\s*' . $string(5, 3) . '\s*)?,?\s*\)/',
        $code,
        $contributions,
        PREG_SET_ORDER
    );
    foreach ($contributions as $contribution) {
        if ($contribution['t1'] !== $module) {
            continue;
        }
        $path_inside = ($contribution['t2'] ?? '') === '' ? 'lang/' : $contribution['t2'];
        $contributed_pattern = ($contribution['t3'] ?? '') === '' ? $module . '_%s' : $contribution['t3'];
        if (trim($path_inside, '/') !== 'lang') {
            return "$component_file contributes module '$module' from '" . $printable($path_inside) . "', not from lang/.";
        }
        if ($contributed_pattern !== $pattern) {
            return "$component_file contributes module '$module' with the file name pattern '" . $printable($contributed_pattern)
                . "', but the run uses '" . $printable($pattern) . "' (see --pattern).";
        }

        return null;
    }

    return "$component_file contributes no ComponentLanguageFileDirectory for module '$module' (expected: "
        . "\$contribute[LanguageFileDirectory::class] = fn(): LanguageFileDirectory => new ComponentLanguageFileDirectory(\$this, '$module'); "
        . "register the contribution first).";
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
$skip_unmigratable_keys = false;
$remove_from_lang = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--pattern=')) {
        $pattern = substr($argument, strlen('--pattern='));
        continue;
    }
    if ($argument === '--skip-unmigratable-keys') {
        $skip_unmigratable_keys = true;
        continue;
    }
    if ($argument === '--remove-from-lang') {
        $remove_from_lang = true;
        continue;
    }
    // a mistyped option must not end up as module name or output directory
    if (str_starts_with($argument, '-')) {
        fwrite(STDERR, "FAILURE: unknown option '" . $printable($argument) . "' (known: --pattern=<pattern>, --skip-unmigratable-keys, --remove-from-lang).\n");
        exit(1);
    }
    $arguments[] = $argument;
}
$module = $arguments[0] ?? 'tos';
$reference_lang_key = $arguments[1] ?? 'de';
// a module name is a lng_data module: it names the output directory and the files
if (preg_match('/\A[A-Za-z0-9_]+\z/', $module) !== 1 || preg_match('/\A[A-Za-z0-9_]+\z/', $reference_lang_key) !== 1) {
    fwrite(STDERR, "FAILURE: module and reference language may only consist of A-Z, a-z, 0-9 and \"_\".\n");
    exit(1);
}
$pattern ??= $module . '_%s';
try {
    MigratedLanguageFilePaths::assertValidShippedFileNamePattern($pattern);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$repo_root = dirname(__DIR__, 5);

// --remove-from-lang takes away the module's only other source - only if its .po files are written
// where the installation reads them: checked before anything is read or written
if ($remove_from_lang) {
    $target_problem = $removal_target_problem($repo_root, $arguments[2] ?? null, $module, $pattern);
    if ($target_problem !== null) {
        fwrite(STDERR, "FAILURE: --remove-from-lang: $target_problem Nothing was written.\n");
        exit(1);
    }
}

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
$lang_dir = $repo_root . '/lang';

$lang_files = glob($lang_dir . '/ilias_*.lang');
sort($lang_files);
if ($lang_files === []) {
    fwrite(STDERR, "No lang files found under $lang_dir\n");
    exit(1);
}

$per_language = [];
// lang_key => path, hash and number of the module's lines of every file read (for --remove-from-lang)
$read_files = [];
// lang_key => identifier => whether all occurrences are the same, see $find_duplicate_keys
$duplicates = [];
$unsafe_files = [];
foreach ($lang_files as $file) {
    if (preg_match('/ilias_([a-z]+)\.lang\z/', $file, $m) !== 1) {
        fwrite(STDERR, "WARNING: skipping $file - no language key in its name.\n");
        continue;
    }
    // a symbolic link (e.g. to another language's file) would only fail when written, after other
    // files are changed already
    if (is_link($file) || !is_file($file)) {
        if ($remove_from_lang) {
            $unsafe_files[] = $file;
            continue;
        }
        if (!is_file($file)) {
            fwrite(STDERR, "WARNING: skipping $file - no regular file.\n");
            continue;
        }
    }
    $lang_key = $m[1];
    $content = $read_file($file);
    $lines = $module_lines($file, $content, $module);
    $per_language[$lang_key] = $entries_of($lines);
    $read_files[$lang_key] = ['path' => $file, 'hash' => hash('sha256', $content), 'lines' => count($lines)];
    $duplicates[$lang_key] = $find_duplicate_keys($lines);
}
if ($unsafe_files !== []) {
    fwrite(STDERR, "FAILURE: --remove-from-lang only changes regular files, these are symbolic links or no files (nothing was written):\n  "
        . implode("\n  ", $unsafe_files) . "\n");
    exit(1);
}

// a module removed with --remove-from-lang before (or a misspelled module name): nothing to convert,
// and the existing .po/.pot - the module's only source now - must stay as they are
if (array_sum(array_column($read_files, 'lines')) === 0) {
    fwrite(STDERR, "FAILURE: module '$module' has no lines in any of the " . count($read_files) . " lang/ilias_*.lang files under $lang_dir"
        . " - most likely it is migrated already (its lines removed with --remove-from-lang), or the module name is misspelled."
        . " Nothing was written; existing .po/.pot files are unchanged.\n");
    exit(1);
}

if (!isset($per_language[$reference_lang_key])) {
    fwrite(STDERR, "Reference language '$reference_lang_key' not found.\n");
    exit(1);
}
$reference_keys = array_map('strval', array_keys($per_language[$reference_lang_key]));

// fail before writing anything rather than silently keeping one of the occurrences - on request, a
// key whose occurrences are all the same is used once, and one that is left out anyway (see below)
// does not matter
$duplicate_report = [];
$identical_duplicate_report = [];
foreach ($duplicates as $lang_key => $keys) {
    foreach ($keys as $key => $identical) {
        $left_out = (string) $key === '' || !in_array((string) $key, $reference_keys, true);
        if ($skip_unmigratable_keys && ($identical || $left_out)) {
            $identical_duplicate_report[$lang_key][] = $key;
        } else {
            $duplicate_report[$lang_key][] = $key;
        }
    }
}
if ($duplicate_report !== []) {
    fwrite(STDERR, "FAILURE: module '$module' has duplicate identifiers in the source .lang files (nothing was written):\n");
    foreach ($duplicate_report as $lang_key => $duplicates) {
        fwrite(STDERR, "  $lang_key: " . $format_keys($duplicates) . "\n");
    }
    if (!$skip_unmigratable_keys) {
        fwrite(STDERR, "  (--skip-unmigratable-keys keeps one occurrence of a key whose occurrences all have the same value)\n");
    }
    exit(1);
}
if ($identical_duplicate_report !== []) {
    fwrite(STDERR, "WARNING: module '$module' has duplicate identifiers in the source .lang files - all occurrences the same, one is used; or left out anyway, see below (--skip-unmigratable-keys):\n");
    foreach ($identical_duplicate_report as $lang_key => $duplicates) {
        fwrite(STDERR, "  $lang_key: " . $format_keys($duplicates) . "\n");
    }
}

// The catalogs are built from the reference keys, so every language's keys must be a subset of the
// reference language's - a key only another language has would otherwise be dropped silently. The
// empty key can never be migrated (msgid "" is the PO header), in no language.
$unmigratable_keys = [];
foreach ($per_language as $lang_key => $entries) {
    $unmigratable = array_values(array_diff(array_map('strval', array_keys($entries)), $reference_keys));
    if (isset($entries[''])) {
        $unmigratable = array_values(array_unique(['', ...$unmigratable]));
    }
    if ($unmigratable !== []) {
        $unmigratable_keys[$lang_key] = $unmigratable;
    }
}
if ($unmigratable_keys !== [] && !$skip_unmigratable_keys) {
    fwrite(STDERR, "FAILURE: module '$module' has keys that the reference language '$reference_lang_key' does not have, or the empty key (nothing was written):\n");
    foreach ($unmigratable_keys as $lang_key => $keys) {
        fwrite(STDERR, "  $lang_key: " . $format_keys($keys) . "\n");
    }
    fwrite(STDERR, "  (--skip-unmigratable-keys leaves these keys out instead)\n");
    exit(1);
}
if ($unmigratable_keys !== []) {
    fwrite(STDERR, "WARNING: module '$module' has keys that the reference language '$reference_lang_key' does not have, or the empty key - left out (--skip-unmigratable-keys):\n");
    foreach ($unmigratable_keys as $lang_key => $keys) {
        fwrite(STDERR, "  $lang_key: " . $format_keys($keys) . "\n");
        foreach ($keys as $key) {
            unset($per_language[$lang_key][$key]);
        }
    }
}

$reference_entries = $per_language[$reference_lang_key];
if ($reference_entries === []) {
    $languages_with_lines = array_keys(array_filter($read_files, static fn(array $read): bool => $read['lines'] > 0));
    fwrite(STDERR, "FAILURE: module '$module' has no (migratable) entries in the reference language '$reference_lang_key',"
        . " but lines in " . implode(', ', $languages_with_lines) . ". Nothing was written.\n");
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
    fwrite(STDERR, "FAILURE: plurals.json does not fit module '$module' (nothing was written):\n  " . implode("\n  ", array_map($printable, $plural_problems)) . "\n");
    exit(1);
}

// English language names for the "Language-Team" header (see the file docblock)
$language_names = [];
$english_lang_file = $repo_root . '/lang/ilias_en.lang';
if (is_file($english_lang_file)) {
    foreach ($parse_module($english_lang_file, 'meta') as $key => $entry) {
        $name = trim($entry['value']);
        if (str_starts_with((string) $key, 'meta_l_') && $name !== '') {
            $language_names[substr((string) $key, strlen('meta_l_'))] = $name;
        }
    }
}
// once "meta" itself is migrated (and its lines removed from the .lang files), its shipped English
// .po is the source - found under the default file name in a component's lang/ directory
if ($language_names === []) {
    foreach (glob($repo_root . '/components/*/*/lang/meta_en.po') ?: [] as $meta_po) {
        try {
            foreach (TranslationCatalog::fromPoFile($meta_po)->getEntries() as $entry) {
                $name = trim($entry->getTranslation());
                if (in_array($entry->getContext(), [null, 'meta'], true) && str_starts_with($entry->getId(), 'meta_l_') && $name !== '') {
                    $language_names[substr($entry->getId(), strlen('meta_l_'))] = $name;
                }
            }
        } catch (RuntimeException $e) {
            fwrite(STDERR, "WARNING: $meta_po cannot be read for the language names: {$e->getMessage()}\n");
        }
    }
}
foreach (array_keys($per_language) as $lang_key) {
    if (!isset($language_names[$lang_key])) {
        echo "NOTE: no meta_l_$lang_key in lang/ilias_en.lang (or a shipped meta_en.po) - the Language-Team header of '$lang_key' is left as it is.\n";
    }
}

if (!is_dir($output_dir) && !mkdir($output_dir, 0775, true) && !is_dir($output_dir)) {
    throw new RuntimeException("Cannot create $output_dir");
}

// POT
$pot_path = $output_dir . '/' . MigratedLanguageFilePaths::templateFileName($pattern, $module);
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
            $plural_definitions,
            $language_names[$lang_key] ?? null
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
        fwrite(STDERR, "  po_mismatches: " . $printable(implode(', ', $row['po_mismatches'])) . "\n");
    }
}

echo "\n";
echo $all_ok
    ? "OK: all " . count($report) . " languages round-trip identically through PO.\n"
    : "FAILURE: at least one language did not round-trip correctly, see stderr above.\n";

if (!$all_ok || !$remove_from_lang) {
    if (!$all_ok && $remove_from_lang) {
        fwrite(STDERR, "--remove-from-lang: no .lang file was changed.\n");
    }
    exit($all_ok ? 0 : 1);
}

// --remove-from-lang: every line of the module - including the ones left out above - leaves every
// .lang file read, so the module's .po files are its only source. All new contents are computed and
// checked first: if one file cannot be processed, no file is changed.
$new_lang_contents = [];
$removal_problems = [];
foreach ($read_files as $lang_key => $read) {
    $content = file_get_contents($read['path']);
    if ($content === false || hash('sha256', $content) !== $read['hash']) {
        $removal_problems[] = "{$read['path']} could not be read again or changed during the run";
        continue;
    }
    [$new_content, $removed] = $without_module_lines($content, $module);
    // e.g. a line of the module above "<!-- language file start -->" - read, but never removed
    if ($removed !== $read['lines']) {
        $removal_problems[] = "{$read['path']}: {$read['lines']} line(s) of the module were read, but $removed would be removed";
        continue;
    }
    if ($removed > 0) {
        $new_lang_contents[$lang_key] = [$new_content, $removed];
    }
}
if ($removal_problems !== []) {
    fwrite(STDERR, "FAILURE: --remove-from-lang: no .lang file was changed (the .po/.pot files are written):\n  " . implode("\n  ", $removal_problems) . "\n");
    exit(1);
}

echo "\n";
$removed_total = 0;
foreach ($new_lang_contents as $lang_key => [$new_content, $removed]) {
    $path = $read_files[$lang_key]['path'];
    try {
        AtomicFileWriter::write($path, $new_content, $lang_dir);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "FAILURE: --remove-from-lang: {$e->getMessage()} - the files listed above are changed already, the others not.\n");
        exit(1);
    }
    $removed_total += $removed;
    printf("removed %5d line(s) of '%s' from %s\n", $removed, $module, 'lang/' . basename($path));
}
echo "OK: removed $removed_total line(s) of module '$module' from " . count($new_lang_contents) . " .lang file(s).\n";

exit(0);
