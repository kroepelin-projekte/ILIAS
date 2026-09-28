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

use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\TranslationMarkupPolicy;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use ILIAS\Language\ComponentTranslation\PlainLogText;
use ILIAS\Language\ComponentTranslation\PluralForms;

/**
 * @author   Richard Klees <richard.klees@concepts-and-training.de>
 */
class ilPluginLanguage
{
    protected ilPluginInfo $plugin_info;

    public function __construct(ilPluginInfo $plugin_info)
    {
        $this->plugin_info = $plugin_info;
    }

    protected function getLanguageDirectory(): string
    {
        return $this->plugin_info->getPath() . "/lang";
    }

    /**
     * Get array of all language files in the plugin
     *
     * A plugin may ship a language as `ilias_<lang>.po` instead of `ilias_<lang>.lang` (see
     * readPoFile()); if it ships both, the `.po` is used.
     *
     * @return array of [key => "en" (e.g.), file => ...]
     */
    public function getAvailableLangFiles(): array
    {
        $directory = $this->getLanguageDirectory();
        if (!@is_dir($directory)) {
            return [];
        }

        $lang_files = [];
        $po_files = [];

        $dir = opendir($directory);
        while ($file = readdir($dir)) {
            if ($file === "." || $file === "..") {
                continue;
            }

            // directories
            if (!@is_file($directory . "/" . $file)) {
                continue;
            }
            if (strpos($file, "ilias_") === 0 && substr($file, strlen($file) - 5) === ".lang") {
                $lang_files[substr($file, 6, 2)] = [
                    "key" => substr($file, 6, 2),
                    "file" => $file
                ];
            } elseif (preg_match('/\Ailias_([a-z]{2})\.po\z/', $file, $matches) === 1) {
                $po_files[$matches[1]] = [
                    "key" => $matches[1],
                    "file" => $file
                ];
            }
        }
        closedir($dir);

        // A language shipped as .po and as .lang is read from the .po
        return array_values(array_replace($lang_files, $po_files));
    }

    public function hasAvailableLangFiles(): bool
    {
        return count($this->getAvailableLangFiles()) > 0;
    }

    public function getPrefix(): string
    {
        $plugin = $this->plugin_info;
        $component = $plugin->getComponent();
        $slot = $plugin->getPluginSlot();

        return $component->getId() . "_" . $slot->getId() . "_" . $plugin->getId();
    }

    /**
     * Update all or selected languages
     *
     * @var array|null $a_lang_keys keys of languages to be updated (null for all)
     */
    public function updateLanguages(?array $a_lang_keys = null): void
    {
        // get the keys of all installed languages if keys are not provided
        if (!isset($a_lang_keys)) {
            $a_lang_keys = ilObjLanguage::getLangKeysOfInstalledLanguages();
        }

        $langs = $this->getAvailableLangFiles();

        $prefix = $this->getPrefix();

        foreach ($langs as $lang) {
            // check if the language should be updated, otherwise skip it
            if (!in_array($lang['key'], $a_lang_keys, true)) {
                continue;
            }

            $file = $this->getLanguageDirectory() . "/" . $lang["file"];
            $values = str_ends_with($lang["file"], ".po") ? $this->readPoFile($file) : $this->readLangFile($file);
            if ($values === null) {
                // a broken .po: this language of the plugin is left as it is (logged)
                continue;
            }
            $lang_array = [];

            // get locally changed variables of the module (these should be kept)
            $local_changes = ilObjLanguage::_getLocalChangesByModule($lang['key'], $prefix);

            foreach ($values as $key => $value) {
                $identifier = $prefix . "_" . $key;

                if (isset($local_changes[$identifier])) {
                    $lang_array[$identifier] = $local_changes[$identifier];
                } else {
                    $lang_array[$identifier] = $value;
                    ilObjLanguage::replaceLangEntry($prefix, $identifier, $lang["key"], $value);
                }
            }

            // This re-applies the plugin's OWN shipped `.lang` file (merged with locally-changed
            // entries, kept as-is above) - the plugin-language analog of a core-component update, so
            // "original" may be refreshed the same way (see MigratedLanguageFileSync::sync()'s
            // docblock); like for a core module, only the delta to a shipped .po is written. Currently
            // always a no-op for the files: a plugin cannot contribute a LanguageFileDirectory yet (the
            // component graph is built from components/ only, cli/build_bootstrap.php) - a plugin .po
            // is read into the database only (readPoFile()), no overlay or artifact is written.
            ilObjLanguage::replaceLangModule($lang["key"], $prefix, $lang_array, true);
        }
    }

    /**
     * The entries of a plugin `.lang` file, key (without the plugin prefix) => value. Like for a
     * plugin `.po` (see readPoFile()), markup TranslationMarkupPolicy does not allow is cleaned and
     * logged, not rejected - the values are shipped ones.
     *
     * @return array<string, string>
     */
    private function readLangFile(string $file): array
    {
        $values = [];
        $txt = file($file);
        if (is_array($txt)) {
            foreach ($txt as $row) {
                if ($row[0] !== "#" && strpos($row, "#:#") > 0) {
                    $a = explode("#:#", trim($row));
                    $values[trim($a[0])] = trim($a[1]);
                }
            }
        }

        return $this->cleanShippedValues($file, $values);
    }

    /**
     * $values with markup TranslationMarkupPolicy does not allow removed - every cleaned value is
     * logged with what was removed.
     *
     * @param array<string, string> $values key => value
     * @return array<string, string>
     */
    private function cleanShippedValues(string $file, array $values): array
    {
        $policy = new TranslationMarkupPolicy();
        foreach ($policy->findInvalidValues($values) as $key => $violations) {
            $values[$key] = $policy->sanitize($values[$key]);
            self::logWarning(sprintf(
                'Markup not allowed in a language value, %s (key "%s"): removed %s',
                $file,
                $key,
                implode(', ', $violations)
            ));
        }

        return $values;
    }

    /**
     * The entries of a plugin `.po` file, key (msgid, without the plugin prefix, like in the `.lang`
     * file) => value, or `null` if the file cannot be read or parsed (logged - the plugin update goes
     * on with its other languages).
     *
     * Only entries without msgctxt are read: the file belongs to exactly one module, the plugin's,
     * whose name (the prefix) must not appear in the file, since it changes when the plugin becomes a
     * component. An entry with a msgctxt - also an empty one - is ignored and logged instead of being
     * guessed into this module. The values are shipped ones: markup TranslationMarkupPolicy does not
     * allow is cleaned (and logged), like the build does for the shipped `.po` of a component, not
     * rejected. Extracted comments ("#.") are not stored, like the "###" comments of a `.lang` file
     * on this path. Fuzzy entries are read too, entries without translation are not. A plural message
     * is read with the value of its default form (msgstr[1] with at least two forms, see
     * PluralForms) and logged: plugins are still served from the database, which holds one value per
     * key, so ntxt() falls back to that value for them.
     *
     * @return array<string, string>|null
     */
    private function readPoFile(string $file): ?array
    {
        try {
            $catalog = TranslationCatalog::fromPoFile($file);
        } catch (\RuntimeException $e) {
            self::logWarning(sprintf(
                'The plugin language file "%s" cannot be read - this language of the plugin is not updated: %s',
                $file,
                $e->getMessage()
            ));
            return null;
        }

        $values = [];
        $ignored = [];
        $plurals = [];
        $plural_forms = null;
        foreach ($catalog->getEntries() as $entry) {
            if ($entry->getContext() !== null) {
                $ignored[] = $entry->getId();
                continue;
            }
            $value = $entry->getTranslation();
            if ($entry->isPlural()) {
                // The database holds one value per key: the default form (see PluralForms)
                $plural_forms ??= PluralForms::fromHeaderOrGermanic(
                    $catalog->getHeader('Plural-Forms'),
                    static fn(string $message) => self::logWarning(sprintf('Plugin language file "%s": %s', $file, $message))
                );
                $value = $plural_forms->defaultValueOf($entry->getPluralTranslations());
                $plurals[] = $entry->getId();
            }
            if ($value !== '') {
                $values[$entry->getId()] = $value;
            }
        }
        if ($plurals !== []) {
            self::logWarning(sprintf(
                'Plural messages in the plugin language file "%s" are stored with their default form only (plugins are served from the database): %s',
                $file,
                implode(', ', $plurals)
            ));
        }
        $values = $this->cleanShippedValues($file, $values);
        if ($ignored !== []) {
            self::logWarning(sprintf(
                'Entries with a msgctxt in the plugin language file "%s" are ignored (a plugin .po has none): %s',
                $file,
                implode(', ', $ignored)
            ));
        }

        return $values;
    }

    /**
     * Logs to the `lang` component logger where it is available, to the PHP error log otherwise (e.g.
     * in a Setup context, see ilPluginLanguageUpdatedObjective) - a plugin language update must never
     * fail just because it cannot log.
     */
    private static function logWarning(string $message): void
    {
        global $DIC;
        $message = PlainLogText::of($message);
        try {
            $DIC->logger()->forComponent('lang')->warning($message);
        } catch (\Throwable) {
            error_log($message);
        }
    }

    public function uninstall(): void
    {
        global $DIC;
        $ilDB = $DIC->database();

        // remove all language entries (see ilObjLanguage)
        // see updateLanguages
        $prefix = $this->getPrefix();
        if ($prefix) {
            $ilDB->manipulate(
                "DELETE FROM lng_data" .
                " WHERE module = " . $ilDB->quote($prefix, "text")
            );
            $ilDB->manipulate(
                "DELETE FROM lng_modules" .
                " WHERE module = " . $ilDB->quote($prefix, "text")
            );

            $this->removeMigratedMoFiles($prefix);
        }
    }

    /**
     * The overlay counterpart to the raw DB deletes above, for a plugin migrated to the PO/MO pilot -
     * analogous to ilObjLanguage::removeMigratedMoFiles(), which closes the same gap for uninstalling a whole
     * language. Without this, uninstalling a plugin left its compiled overlay .mo/.po files completely
     * untouched on disk for every language: lng_data/lng_modules are gone, but
     * ilLanguage::loadLanguageModule()/txtlng() never check whether $prefix still belongs to an
     * installed plugin before reading a migrated module's overlay .mo file - it would keep serving the
     * now-uninstalled plugin's content forever.
     *
     * Iterates every language the plugin ships a `.lang` file for (not just currently installed
     * languages, see getAvailableLangFiles()) rather than ilObjLanguage::getLangKeysOfInstalledLanguages():
     * a rollback-relevant overlay can exist for a language that was itself uninstalled later, and
     * removing it too is exactly what a plugin uninstall should do -
     * MigratedLanguageFileSync::removeOverlay() is a no-op per language/module pair anyway when there
     * is nothing to remove, so scanning every shipped language costs nothing extra.
     *
     * Same no-op/failure posture as ilObjLanguage::removeMigratedMoFiles(): silently does nothing if no
     * LanguageFileDirectoryManager is registered at all, and logs and swallows a removal failure per
     * language rather than throwing or aborting the remaining languages - the DB-side uninstall above
     * already succeeded and must not be undone or blocked by a problem with the file mirror (e.g. a
     * read-only overlay directory).
     */
    private function removeMigratedMoFiles(string $prefix): void
    {
        global $DIC;

        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return;
        }
        // The prefix is composed of plugin metadata (component, slot and plugin id). Overlay paths are
        // only ever built from a contributed LanguageFileDirectory matching it, never from the prefix
        // itself - still, a prefix that cannot be a module name is not looked up at all.
        if (preg_match('/^[A-Za-z0-9_]+\z/', $prefix) !== 1) {
            $DIC->logger()->forComponent('lang')->warning(PlainLogText::of(sprintf(
                'Not removing any overlay for the plugin language module "%s": not a valid module name.',
                $prefix
            )));
            return;
        }

        /** @var LanguageFileDirectoryManager $manager */
        $manager = $DIC[LanguageFileDirectoryManager::class];
        $client_data_dir = MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH);

        foreach ($this->getAvailableLangFiles() as $lang) {
            try {
                MigratedLanguageFileSync::removeOverlay($manager, $lang['key'], $prefix, $client_data_dir);
            } catch (\Throwable $t) {
                $DIC->logger()->forComponent('lang')->warning(sprintf(
                    'Could not remove migrated overlay file for module "%s", language "%s": %s',
                    $prefix,
                    $lang['key'],
                    $t->getMessage()
                ));
            }
        }
    }

    /**
     * Load language module for plugin
     */
    public function loadLanguageModule(): void
    {
        global $DIC;
        $lng = $DIC->language();

        if (is_object($lng)) {
            $lng->loadLanguageModule($this->getPrefix());
        }
    }

    /**
     * Get Language Variable (prefix will be prepended automatically)
     */
    public function txt(string $a_var): string
    {
        global $DIC;
        $lng = $DIC->language();
        $this->loadLanguageModule();

        return $lng->txt($this->getPrefix() . "_" . $a_var, $this->getPrefix());
    }
}
