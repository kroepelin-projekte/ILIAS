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

use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedTranslations;
use ILIAS\Language\LanguageIdentifier;

/**
 * language handling
 *
 * this class offers the language handling for an application.
 * the constructor is called with a small language abbreviation
 * e.g. $lng = new Language("en");
 * with
 * e.g. $lng->txt("user_updated");
 * you can translate a lang-topic into the actual language
 *
 * Two coexisting backends per module: the legacy DB tables lng_data/lng_modules (for every module
 * not migrated yet), and - for a module migrated for the requested language - its build served
 * through native gettext with the overlay of the local changes on top (see MigratedTranslations),
 * never the DB. The values of a migrated module do not go into $text: loadLanguageModule() records
 * its identifiers in $migrated_key_modules, and txt() looks them up when asked. The DB path exists
 * to be fully replaced and eventually removed as more modules migrate onto the file-based one, not
 * to be maintained forever alongside it.
 *
 * translate() reads an identifier given as LanguageIdentifier (enum case of a module) from exactly its
 * module, in both backends, without loadLanguageModule() beforehand - see there.
 *
 * @author Peter Gabriel <pgabriel@databay.de>
 * @version $Id$
 */
class ilLanguage implements \ILIAS\Language\Language
{
    public ILIAS $ilias;
    public array $text = [];
    public string $lang_default;
    public string $lang_user;
    public string $lang_path;
    public string $lang_key;
    public string $lang_name;
    public string $separator = "#:#";
    public string $comment_separator = "###";
    public array $loaded_modules = array();
    protected static array $used_topics = array();
    protected static array $used_modules = array();

    /**
     * The installed languages of this request - the migrated state is only served for them, see
     * isInstalledLanguage(). Static, since _lookupEntry() is; taken from the list the constructor
     * reads anyway (no query of its own), `null` until known.
     *
     * @var list<string>|null
     */
    private static ?array $installed_languages = null;

    /**
     * Identifier => module for the identifiers of the migrated modules loaded so far (see
     * loadLanguageModule()), the module loaded last is responsible: a migrated module overwrites the
     * entries of its identifiers, a module that is not migrated removes the entries of its
     * identifiers (its value in $text wins then). An identifier the responsible migrated module does
     * not translate for the language gives "-identifier-", never the value of a module loaded before
     * (see txt()).
     *
     * @var array<string, string>
     */
    protected array $migrated_key_modules = [];

    /**
     * Whether a module ships a `.po` for a language that is not built, per "<module>|<lang>" - see
     * isShippedWithoutBuild().
     *
     * @var array<string, bool>
     */
    private static array $shipped_without_build = [];

    /**
     * Identifier => value per module that is not migrated, for the language of this instance - read
     * by loadLanguageModule() or by translate() (see moduleTexts()), so translate() serves the value
     * of the module a LanguageIdentifier names, not the one of the module loaded last ($text). Holds the
     * same arrays as cached_modules or $text was merged from (copy-on-write, no copy).
     *
     * @var array<string, array<string|int, mixed>>
     */
    private array $module_texts = [];
    protected array $cached_modules = array();
    protected array $map_modules_txt = array();
    protected bool $usage_log_enabled = false;
    protected static array $lng_log = array();
    protected string $cust_lang_path;
    protected ilLogger $log;
    protected ilCachedLanguage $global_cache;

    /**
     * Constructor
     * read the single-language file and put this in an array text.
     * the text array is two-dimensional. First dimension is the language.
     * Second dimension is the languagetopic. Content is the translation.
     *
     * $a_lang_key    language code (two characters), e.g. "de", "en", "in"
     * Return false if reading failed, otherwise true
     */
    public function __construct(string $a_lang_key)
    {
        global $DIC;
        $client_ini = $DIC->clientIni();

        $this->log = $DIC->logger()->forComponent('lang');

        $this->lang_key = $a_lang_key;

        $this->usage_log_enabled = self::isUsageLogEnabled();

        $this->lang_path = ILIAS_ABSOLUTE_PATH . "/lang";
        $this->cust_lang_path = ILIAS_ABSOLUTE_PATH . "/lang/customizing";

        $this->lang_default = $client_ini->readVariable("language", "default") ?? 'en';
        $this->lang_user = $this->lang_default;

        if ($DIC->offsetExists("ilSetting")) {
            $ilSetting = $DIC->settings();
            if ($ilSetting->get("language") != "") {
                $this->lang_default = $ilSetting->get("language");
            }
        }
        if ($DIC->offsetExists("ilUser")) {
            $ilUser = $DIC->user();
            $this->lang_user = $ilUser->getLanguage();
        }

        $langs = $this->getInstalledLanguages();
        self::$installed_languages = array_values(array_map('strval', $langs));

        if (!in_array($this->lang_key, $langs, true)) {
            $this->lang_key = $this->lang_default;
        }

        $this->global_cache = ilCachedLanguage::getInstance($this->lang_key);
        if ($this->global_cache->isActive()) {
            $this->cached_modules = $this->global_cache->getTranslations();
        }
        $this->loadLanguageModule("common");
    }

    /**
     * Return lang key
     */
    public function getLangKey(): string
    {
        return $this->lang_key;
    }

    /**
     * Return default language
     */
    public function getDefaultLanguage(): string
    {
        return $this->lang_default ?? "en";
    }

    /**
     * Return text direction
     */
    public function getTextDirection(): string
    {
        $rtl = array("ar", "fa", "ur", "he");
        if (in_array($this->getContentLanguage(), $rtl)) {
            return "rtl";
        }
        return "ltr";
    }

    /**
     * Return content language
     */
    public function getContentLanguage(): string
    {
        if ($this->getUserLanguage()) {
            return $this->getUserLanguage();
        }
        return $this->getLangKey();
    }

    /**
     * gets the text for a given topic in a given language
     * if the topic is not in the list, the topic itself with "-" will be returned
     */
    public function txtlng(string $a_module, string $a_topic, string $a_language): string
    {
        if (strcmp($a_language, $this->lang_key) === 0) {
            return $this->txt($a_topic);
        } else {
            return self::_lookupEntry($a_language, $a_module, $a_topic);
        }
    }

    /**
     * gets the text for a given topic
     * if the topic is not in the list, the topic itself with "-" will be returned
     */
    public function txt(string $a_topic, string $a_default_lang_fallback_mod = ""): string
    {
        if (empty($a_topic)) {
            return "";
        }

        // remember the used topics
        self::$used_topics[$a_topic] = $a_topic;

        $module = $this->migrated_key_modules[$a_topic] ?? null;
        if ($module !== null) {
            $translation = $this->migratedText($module, $a_topic);
            if ($translation !== null) {
                if ($this->usage_log_enabled) {
                    self::logUsage($module, $a_topic);
                }
                return $translation;
            }
            // Not translated in the migrated module loaded last (the one responsible for it): no
            // value - deliberately not the value of a module loaded before (migrated or not), which
            // with the same identifier mostly means something else ("-key-" is noticed, a wrong
            // text hardly). A behaviour change against the .lang/database path, where array_merge()
            // kept the earlier value. Only the fallback module the caller names still applies.
            $translation = "";
        } else {
            $translation = $this->text[$a_topic] ?? "";
        }

        if ($translation === "" && $a_default_lang_fallback_mod !== "") {
            // #13467 - try current language first (could be missing module)
            if ($this->lang_key != $this->lang_default) {
                $translation = self::_lookupEntry(
                    $this->lang_key,
                    $a_default_lang_fallback_mod,
                    $a_topic
                );
            }
            // try default language last
            if ($translation === "" || $translation === "-" . $a_topic . "-") {
                $translation = self::_lookupEntry(
                    $this->lang_default,
                    $a_default_lang_fallback_mod,
                    $a_topic
                );
            }
        }


        if ($translation === "") {
            if (ILIAS_LOG_ENABLED && is_object($this->log)) {
                $this->log->debug("Language (" . $this->lang_key . "): topic -" . $a_topic . "- not present");
            }
            return "-" . $a_topic . "-";
        }

        if ($this->usage_log_enabled) {
            self::logUsage($this->map_modules_txt[$a_topic] ?? "", $a_topic);
        }

        return $translation;
    }

    /**
     * The text of $a_topic for the quantity $a_n: for a plural message of a migrated module (see
     * loadLanguageModule()) the form the "Plural-Forms" rule of the language selects for $a_n
     * (evaluated by native gettext, see MigratedTranslations). In every other case - a module that is
     * not migrated, a plugin, an identifier without plural forms, a form that is empty - exactly what
     * txt($a_topic, $a_default_lang_fallback_mod) returns. The quantity is not inserted into the
     * text; callers do that, e.g. with sprintf().
     *
     * If txt() would serve $a_topic from $a_default_lang_fallback_mod (no loaded module has a value
     * for it), the fallback module is read like translate() reads a LanguageIdentifier: no
     * loadLanguageModule() needed, a module migrated for the language gives the plural form, the
     * language of this instance first and then the default language (#13467).
     */
    public function ntxt(string $a_topic, int $a_n, string $a_default_lang_fallback_mod = ""): string
    {
        $module = $a_topic === '' ? null : ($this->migrated_key_modules[$a_topic] ?? null);
        if ($module === null && $a_default_lang_fallback_mod !== '' && ($this->text[$a_topic] ?? '') === '') {
            return $this->translateInModule($a_default_lang_fallback_mod, $a_topic, $a_n, $this->migratedLangKey());
        }
        $value = $module === null
            ? null
            : MigratedTranslations::pluralText($module, $this->migratedLangKey(), $a_topic, $a_n, self::clientDataDir());
        if ($value !== null && $value !== '') {
            self::$used_topics[$a_topic] = $a_topic;
            if ($this->usage_log_enabled) {
                self::logUsage($module, $a_topic);
            }
            return $value;
        }

        return $this->txt($a_topic, $a_default_lang_fallback_mod);
    }

    /**
     * Check if language entry exists
     */
    public function exists(string $a_topic): bool
    {
        $module = $this->migrated_key_modules[$a_topic] ?? null;
        if ($module !== null) {
            return $this->migratedText($module, $a_topic) !== null;
        }

        return isset($this->text[$a_topic]);
    }

    /**
     * The text of $key, optionally for the quantity $n (plural form, see ntxt()) and in the language
     * $lang (default: the language of this instance). The successor of txt(), ntxt() and txtlng():
     *
     * - A LanguageIdentifier (an enum case of a module, see LanguageIdentifier) is read from exactly its module -
     *   no loadLanguageModule() beforehand and no dependency on which module was loaded last. A
     *   module migrated for the language is looked up directly in its build and overlay (see
     *   MigratedTranslations); of a module that is not migrated, the values for the language of this
     *   instance are read (from the cache of lng_modules, else lng_modules) and kept for the request,
     *   for any other language its entry is read from lng_data. Without side effect on txt() & Co.:
     *   nothing goes into $text or the loaded modules, nothing changes which module is responsible
     *   for an identifier (as a migrated module is never loaded here either). If the module has no
     *   (non-empty) text for the language, the one of the default language is served (like txt() with
     *   a fallback module, #13467), otherwise "-identifier-".
     * - A string without $lang behaves exactly like txt($key) (without $n) or ntxt($key, $n) - the
     *   modules loaded so far decide, the one loaded last wins. With a $lang other than the language of
     *   this instance, the module responsible for $key in this instance is read in $lang - exactly one
     *   lookup: the migrated module the identifier is assigned to (see txt()), else the module loaded
     *   last whose values contain the identifier. No fallback to the default language there (as with
     *   txtlng()) - "-identifier-" if that module has no text in $lang or no module is responsible.
     *
     * A $lang that is no language key (two lower case letters) is treated like a language without
     * texts: nothing is looked up for it. An empty identifier (as for txt(): also "0") gives "".
     *
     * $n only selects a plural form of a plural message of a migrated module; everywhere else it is
     * ignored. The quantity is not inserted into the text; callers do that, e.g. with sprintf().
     */
    public function translate(string|LanguageIdentifier $key, ?int $n = null, ?string $lang = null): string
    {
        if ($key instanceof LanguageIdentifier) {
            return $this->translateInModule($key->module(), (string) $key->value, $n, $lang ?? $this->migratedLangKey());
        }
        if ($lang === null || $lang === $this->migratedLangKey()) {
            return $n === null ? $this->txt($key) : $this->ntxt($key, $n);
        }

        return $this->translateInLanguage($key, $n, $lang);
    }

    /**
     * translate() for an identifier of a known module, see there.
     */
    private function translateInModule(string $module, string $a_topic, ?int $n, string $lang_key): string
    {
        // like txt(""): never the header of a catalog
        if (empty($a_topic)) {
            return '';
        }
        self::$used_topics[$a_topic] = $a_topic;

        $translation = $this->moduleText($module, $a_topic, $n, $lang_key);
        $default_lang_key = $this->getDefaultLanguage();
        if ($translation === null && $lang_key !== $default_lang_key) {
            $translation = $this->moduleText($module, $a_topic, $n, $default_lang_key);
        }

        return $translation ?? $this->missingText($a_topic, $lang_key);
    }

    /**
     * translate() for an identifier given as string in a language other than the one of this
     * instance, see there.
     */
    private function translateInLanguage(string $a_topic, ?int $n, string $lang_key): string
    {
        if (empty($a_topic)) {
            return '';
        }
        self::$used_topics[$a_topic] = $a_topic;

        $module = $this->responsibleModule($a_topic);

        return ($module === null ? null : $this->moduleText($module, $a_topic, $n, $lang_key))
            ?? $this->missingText($a_topic, $lang_key);
    }

    /**
     * The module txt() serves $a_topic from in this instance: the migrated module it is assigned to,
     * else the module loaded last (not migrated) whose values contain it - `null` if none.
     */
    private function responsibleModule(string $a_topic): ?string
    {
        $module = $this->migrated_key_modules[$a_topic] ?? null;
        if ($module !== null) {
            return $module;
        }
        foreach (array_reverse($this->loaded_modules) as $loaded_module) {
            if (array_key_exists($a_topic, $this->module_texts[$loaded_module] ?? [])) {
                return (string) $loaded_module;
            }
        }

        return null;
    }

    /**
     * The non-empty text of $a_topic in $module for $lang_key (the plural form for $n, if $n is given
     * and $module is migrated and has one), `null` if there is none. Records the usage of a text found.
     */
    private function moduleText(string $module, string $a_topic, ?int $n, string $lang_key): ?string
    {
        if (!MigratedLanguageFilePaths::isValidLanguageKey($lang_key)) {
            return null;
        }
        if (self::migratedKeysOf($module, $lang_key) !== null) {
            $translation = $n === null
                ? null
                : MigratedTranslations::pluralText($module, $lang_key, $a_topic, $n, self::clientDataDir());
            if ($translation === null || $translation === '') {
                $translation = MigratedTranslations::text($module, $lang_key, $a_topic, self::clientDataDir());
            }
        } elseif ($lang_key === $this->migratedLangKey()) {
            $translation = $this->moduleTexts($module, $lang_key)[$a_topic] ?? null;
        } else {
            $translation = self::lookupDatabaseEntry($lang_key, $module, $a_topic);
        }
        if (!is_string($translation) || $translation === '') {
            return null;
        }

        self::$used_modules[$module] = $module;
        if ($this->usage_log_enabled) {
            self::logUsage($module, $a_topic);
        }

        return $translation;
    }

    /**
     * The values of $module (not migrated) for $lang_key, the language of this instance: as
     * loadLanguageModule() read them, else from cached_modules or lng_modules - without loading the
     * module (see translate()). Empty if it has none.
     *
     * @return array<string|int, mixed>
     */
    private function moduleTexts(string $module, string $lang_key): array
    {
        if (!isset($this->module_texts[$module])) {
            $cached = $this->cached_modules[$module] ?? null;
            $this->module_texts[$module] = is_array($cached) ? $cached : (self::readModuleTexts($module, $lang_key) ?? []);
        }

        return $this->module_texts[$module];
    }

    /**
     * The values of $module for $lang_key from lng_modules, `null` if there is no (valid) row.
     *
     * @return array<string|int, mixed>|null
     */
    private static function readModuleTexts(string $module, string $lang_key): ?array
    {
        global $DIC;
        $ilDB = $DIC->database();

        $q = "SELECT lang_array FROM lng_modules " .
                "WHERE lang_key = " . $ilDB->quote($lang_key, "text") . " AND module = " .
                $ilDB->quote($module, "text");
        $r = $ilDB->query($q);
        $row = $r->fetchRow(ilDBConstants::FETCHMODE_ASSOC);
        if ($row === false) {
            return null;
        }
        $texts = unserialize($row["lang_array"], ["allowed_classes" => false]);

        return is_array($texts) ? $texts : null;
    }

    private function missingText(string $a_topic, string $lang_key): string
    {
        if (ILIAS_LOG_ENABLED && is_object($this->log)) {
            $lang_key = MigratedLanguageFilePaths::isValidLanguageKey($lang_key) ? $lang_key : 'invalid language key';
            $this->log->debug("Language (" . $lang_key . "): topic -" . $a_topic . "- not present");
        }

        return "-" . $a_topic . "-";
    }

    /**
     * Load language module
     */
    public function loadLanguageModule(string $a_module): void
    {
        if (in_array($a_module, $this->loaded_modules, true)) {
            return;
        }

        $this->loaded_modules[] = $a_module;

        // remember the used modules globally
        self::$used_modules[$a_module] = $a_module;

        $lang_key = $this->lang_key;

        if (empty($this->lang_key)) {
            $lang_key = $this->lang_user;
        }

        // PO/MO pilot: a module migrated for $lang_key is served from its build and overlay through
        // native gettext (see MigratedTranslations) - only its identifiers are recorded here.
        // Modules that haven't been migrated yet fall through to the unchanged DB/cache path below.
        // This check must run before the cached_modules check: ilCachedLanguage::isActive() is
        // hard-coded to true, so cached_modules is always populated from lng_modules for every module
        // that still has a DB row there - which migrated modules do on purpose (dual-write, see
        // ilObjLanguage::syncMigratedLanguageFile()).
        $migrated_keys = self::migratedKeysOf($a_module, $lang_key);
        if ($migrated_keys !== null) {
            // the module loaded last is responsible for its identifiers
            $this->migrated_key_modules = $migrated_keys + $this->migrated_key_modules;
            return;
        }

        if (isset($this->cached_modules[$a_module]) && is_array($this->cached_modules[$a_module])) {
            $this->module_texts[$a_module] = $this->cached_modules[$a_module];
            $this->text = array_merge($this->text, $this->cached_modules[$a_module]);
            $this->forgetMigratedKeys($this->cached_modules[$a_module]);

            if ($this->usage_log_enabled) {
                foreach (array_keys($this->cached_modules[$a_module]) as $key) {
                    $this->map_modules_txt[$key] = $a_module;
                }
            }

            return;
        }

        $new_text = self::readModuleTexts($a_module, $lang_key);
        if ($new_text !== null) {
            $this->module_texts[$a_module] = $new_text;
            $this->text = array_merge($this->text, $new_text);
            $this->forgetMigratedKeys($new_text);

            if ($this->usage_log_enabled) {
                foreach (array_keys($new_text) as $key) {
                    $this->map_modules_txt[$key] = $a_module;
                }
            }
        }
    }

    /**
     * A module that is not migrated was loaded after migrated ones: its values in $text win for its
     * identifiers from now on.
     *
     * @param array<string|int, mixed> $text
     */
    private function forgetMigratedKeys(array $text): void
    {
        if ($this->migrated_key_modules !== []) {
            $this->migrated_key_modules = array_diff_key($this->migrated_key_modules, $text);
        }
    }

    /**
     * The value of $a_topic in the migrated module $module for the language of this instance, `null`
     * if it has none (see MigratedTranslations::text()).
     */
    private function migratedText(string $module, string $a_topic): ?string
    {
        return MigratedTranslations::text($module, $this->migratedLangKey(), $a_topic, self::clientDataDir());
    }

    /**
     * The language loadLanguageModule() reads the modules in.
     */
    private function migratedLangKey(): string
    {
        return $this->lang_key !== '' ? $this->lang_key : $this->lang_user;
    }

    /**
     * Whether $lang_key is installed - the migrated state is served for installed languages only; a
     * language that is not installed falls back to lng_modules like a module that is not migrated
     * (usually "-key-"). The list is the one the constructor of the request's ilLanguage (built
     * during initialisation, before any text is read) determined - no query of its own. Without it
     * (no ilLanguage constructed in this request) nothing is restricted.
     */
    private static function isInstalledLanguage(string $lang_key): bool
    {
        return self::$installed_languages === null || in_array($lang_key, self::$installed_languages, true);
    }

    /**
     * $lang_key was uninstalled in this request: from now on its migrated state is not served any
     * more (see isInstalledLanguage()), and what was already read for it is dropped.
     */
    public static function forgetInstalledLanguage(string $lang_key): void
    {
        if (self::$installed_languages !== null) {
            self::$installed_languages = array_values(array_diff(self::$installed_languages, [$lang_key]));
        }
        MigratedTranslations::forgetLanguage($lang_key);
    }

    /**
     * Makes a write of the overlay of $a_module/$lang_key that just happened (see
     * MigratedLanguageFileSync) visible to the rest of the same request.
     */
    public static function invalidateMigratedLanguageFileCache(string $a_module, string $lang_key): void
    {
        MigratedTranslations::invalidate($a_module, $lang_key);
    }

    /**
     * The identifiers of $a_module for $lang_key as identifier => module if (and only if) $a_module
     * is migrated for $lang_key (see MigratedTranslations) - `null` in every other case, so the
     * caller reads lng_modules/lng_data. A module whose component ships a `.po` for $lang_key that is
     * not in the build (no `setup build` since) is migrated as well, but without any identifier:
     * that is reported (see MigratedTranslations::getProblem()), and its identifiers are not found -
     * never read from the database instead.
     *
     * Deliberately only when the CLIENT_DATA_DIR constant is defined, not via
     * MigratedLanguageFilePaths::resolveClientDataDir()'s ilias.ini fallback: reading translations is
     * a runtime concern, and before ilInitialisation::initClientDataDir() has run (e.g. the ilLanguage
     * instances plugin Setup Objectives create) lng_modules is the correct source.
     *
     * @return array<string, string>|null
     */
    private static function migratedKeysOf(string $a_module, string $lang_key): ?array
    {
        if (!defined('CLIENT_DATA_DIR') || !self::isInstalledLanguage($lang_key)) {
            return null;
        }
        $keys = MigratedTranslations::keysOf($a_module, $lang_key, self::clientDataDir());
        if ($keys !== null || !self::isShippedWithoutBuild($a_module, $lang_key)) {
            return $keys;
        }
        MigratedTranslations::reportProblem(sprintf(
            'Module "%s" ships a language file for "%s" that is not built - run "php cli/setup.php build".',
            $a_module,
            $lang_key
        ));

        return [];
    }

    /**
     * Whether $a_module ships a `.po` for $lang_key (see MigratedTranslations::keysOf() for whether it
     * is built).
     */
    private static function isShippedWithoutBuild(string $a_module, string $lang_key): bool
    {
        return self::$shipped_without_build[$a_module . '|' . $lang_key] ??= self::findShippedWithoutBuild($a_module, $lang_key);
    }

    private static function findShippedWithoutBuild(string $a_module, string $lang_key): bool
    {
        $directory = self::findLanguageFileDirectory($a_module);
        if ($directory === null) {
            return false;
        }
        try {
            return is_file(MigratedLanguageFilePaths::shippedBasePath(
                defined('ILIAS_ABSOLUTE_PATH') ? (string) ILIAS_ABSOLUTE_PATH : dirname(__DIR__, 4),
                $directory,
                $lang_key
            ) . '.po');
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private static function clientDataDir(): ?string
    {
        return defined('CLIENT_DATA_DIR') ? (string) CLIENT_DATA_DIR : null;
    }

    /**
     * The LanguageFileDirectoryManager is assembled by Language.php's Component Revision graph
     * from every component's $contribute[LanguageFileDirectory::class] and re-exposed to legacy
     * $DIC-based code (this class included) by AllModernComponents.php under its own class name -
     * see that file and Language.php's $provide block.
     */
    private static function findLanguageFileDirectory(string $a_module): ?LanguageFileDirectory
    {
        global $DIC;

        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return null;
        }

        /** @var LanguageFileDirectoryManager $manager */
        $manager = $DIC[LanguageFileDirectoryManager::class];
        foreach ($manager->getDirectories() as $directory) {
            if ($directory->getPrefix() === $a_module) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * Get installed languages
     */
    public function getInstalledLanguages(): array
    {
        return self::_getInstalledLanguages();
    }

    /**
     * Get installed languages
     *
     * Deliberately separate from ilSetupLanguage::getInstalledLanguages():
     * this one relies on ilObject::_getObjectsByType(), which needs the full
     * runtime object repository and is unavailable during Setup, whereas
     * ilSetupLanguage's variant uses a raw object_data query that works
     * before that machinery exists. Do not merge the two.
     */
    public static function _getInstalledLanguages(): array
    {
        $langlist = ilObject::_getObjectsByType("lng");

        $languages = [];
        foreach ($langlist as $lang) {
            if (strpos($lang["desc"], "installed") === 0) {
                $languages[] = $lang["title"];
            }
        }

        return $languages ?: [];
    }

    public static function _lookupEntry(string $a_lang_key, string $a_mod, string $a_id): string
    {
        // PO/MO pilot: same migrated lookup as loadLanguageModule(), extended to this static,
        // DB-based (lng_data) sibling used by txtlng() and txt()'s fallback-module branch. A module
        // migrated for $a_lang_key is never read from lng_data.
        $migrated_keys = self::migratedKeysOf($a_mod, $a_lang_key);
        if ($migrated_keys !== null) {
            $value = isset($migrated_keys[$a_id])
                ? MigratedTranslations::text($a_mod, $a_lang_key, $a_id, self::clientDataDir())
                : null;
            if ($value === null || $value === '') {
                return "-" . $a_id . "-";
            }
            self::$used_topics[$a_id] = $a_id;
            self::$used_modules[$a_mod] = $a_mod;

            if (self::isUsageLogEnabled()) {
                self::logUsage($a_mod, $a_id);
            }

            return $value;
        }

        $value = self::lookupDatabaseEntry($a_lang_key, $a_mod, $a_id);
        if ($value !== null) {
            // remember the used topics
            self::$used_topics[$a_id] = $a_id;
            self::$used_modules[$a_mod] = $a_mod;

            if (self::isUsageLogEnabled()) {
                self::logUsage($a_mod, $a_id);
            }

            return $value;
        }

        return "-" . $a_id . "-";
    }

    /**
     * The value of $a_id in $a_mod for $a_lang_key from lng_data, `null` if there is none or it is
     * empty.
     */
    private static function lookupDatabaseEntry(string $a_lang_key, string $a_mod, string $a_id): ?string
    {
        global $DIC;
        $ilDB = $DIC->database();

        $set = $ilDB->query(sprintf(
            "SELECT value FROM lng_data WHERE module = %s " .
            "AND lang_key = %s AND identifier = %s",
            $ilDB->quote($a_mod, "text"),
            $ilDB->quote($a_lang_key, "text"),
            $ilDB->quote($a_id, "text")
        ));
        $rec = $ilDB->fetchAssoc($set);

        return isset($rec["value"]) && $rec["value"] != "" ? (string) $rec["value"] : null;
    }

    /**
     * Lookup obj_id of language
     */
    public static function lookupId(string $a_lang_key): int
    {
        global $DIC;
        $ilDB = $DIC->database();

        $query = "SELECT obj_id FROM object_data " . " " .
        "WHERE title = " . $ilDB->quote($a_lang_key, "text") . " " .
            "AND type = " . $ilDB->quote("lng", "text");

        $res = $ilDB->query($query);
        while ($row = $res->fetchRow(ilDBConstants::FETCHMODE_OBJECT)) {
            return (int) $row->obj_id;
        }
        return 0;
    }

    /**
     * Return used topics
     */
    public function getUsedTopics(): array
    {
        asort(self::$used_topics);
        return self::$used_topics;
    }

    /**
     * Return used modules
     */
    public function getUsedModules(): array
    {
        asort(self::$used_modules);
        return self::$used_modules;
    }

    /**
     * Return language of user
     */
    public function getUserLanguage(): string
    {
        return $this->lang_user;
    }

    public function getCustomLangPath(): string
    {
        return $this->cust_lang_path;
    }

    /**
     * Builds a global default language instance
     */
    public static function getFallbackInstance(): ilLanguage
    {
        return new self("en");
    }

    /**
     * Builds the global language object
     */
    public static function getGlobalInstance(): self
    {
        global $DIC;

        $ilSetting = $DIC->settings();

        $ilUser = null;
        if ($DIC->offsetExists("ilUser")) {
            $ilUser = $DIC->user();
        }

        $isset_get_lang = $DIC->http()->wrapper()->query()->has("lang");
        if (!ilSession::get("lang") && !$isset_get_lang && $ilUser instanceof ilObjUser &&
            (!$ilUser->getId() || $ilUser->isAnonymous())) {
            $language_detection = new ilLanguageDetection();
            $language = $language_detection->detect();

            ilSession::set("lang", $language);
        }

        $post_change_lang_to = [];
        if ($DIC->http()->wrapper()->post()->has('change_lang_to')) {
            $post_change_lang_to = $DIC->http()->wrapper()->post()->retrieve(
                'change_lang_to',
                $DIC->refinery()->kindlyTo()->dictOf(
                    $DIC->refinery()->kindlyTo()->string()
                )
            );
        }

        // prefer personal setting when coming from login screen
        // Added check for ilUser->getId > 0 because it is 0 when the language is changed and
        // the terms of service should be displayed
        if ($ilUser instanceof ilObjUser &&
            (($ilUser->getId() && !$ilUser->isAnonymous()))
        ) {
            ilSession::set("lang", $ilUser->getLanguage());
        }

        $get_lang = null;
        if ($isset_get_lang) {
            $get_lang = $DIC->http()->wrapper()->query()->retrieve(
                "lang",
                $DIC->refinery()->kindlyTo()->string()
            );
        }
        ilSession::set("lang", ($isset_get_lang && $get_lang) ? $get_lang : ilSession::get("lang"));

        // check whether lang selection is valid
        $langs = self::_getInstalledLanguages();
        if (!in_array(ilSession::get("lang"), $langs, true)) {
            if ($ilSetting instanceof ilSetting && (string) $ilSetting->get("language", '') !== "") {
                ilSession::set("lang", $ilSetting->get("language"));
            } else {
                ilSession::set("lang", $langs[0]);
            }
        }

        return new self(ilSession::get("lang"));
    }

    /**
     * Transfer text to Javascript
     *
     * @param string|string[] $a_lang_key
     * $a_lang_key language key string or array of language keys
     */

    public function toJS($a_lang_key, ?ilGlobalTemplateInterface $a_tpl = null): void
    {
        global $DIC;
        $tpl = $DIC["tpl"];

        if (!is_object($a_tpl)) {
            $a_tpl = $tpl;
        }

        if (!is_array($a_lang_key)) {
            $a_lang_key = array($a_lang_key);
        }

        $map = array();
        foreach ($a_lang_key as $lk) {
            $map[$lk] = $this->txt($lk);
        }
        $this->toJSMap($map, $a_tpl);
    }

    /**
     * Transfer text to Javascript
     *
     * $a_map array of key value pairs (key is text string, value is content)
     */
    public function toJSMap(array $a_map, ?ilGlobalTemplateInterface $a_tpl = null): void
    {
        global $DIC;
        $tpl = $DIC["tpl"];

        if (!is_object($a_tpl)) {
            $a_tpl = $tpl;
        }

        if (!is_array($a_map)) {
            return;
        }

        foreach ($a_map as $k => $v) {
            if ($v != "") {
                // JSON_HEX_TAG/JSON_HEX_AMP: "<", ">" and "&" become \u003C etc., so a value can never
                // end the surrounding <script> element ("</script>", "<!--") - independent of what
                // TranslationMarkupPolicy lets through. The key is encoded the same way, so a quote in
                // it cannot end the JS string either.
                $flags = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
                $a_tpl->addOnloadCode(
                    "il.Language.setLangVar(" . json_encode((string) $k, $flags) . ", " . json_encode($v, $flags) . ");"
                );
            }
        }
    }

    /**
     * saves tupel of language module and identifier
     */
    protected static function logUsage(string $a_module, string $a_identifier): void
    {
        if ($a_module !== "" && $a_identifier !== "") {
            self::$lng_log[$a_identifier] = $a_module;
        }
    }

    /**
     * checks if language usage log is enabled
     * you need MySQL to use this function
     * this function is automatically enabled if DEVMODE is on
     * this function is also enabled if language_log is 1
     */
    protected static function isUsageLogEnabled(): bool
    {
        global $DIC;
        $ilClientIniFile = $DIC->clientIni();
        $ilDB = $DIC->database();

        if (!$ilClientIniFile instanceof ilIniFile) {
            return false;
        }

        if (defined("DEVMODE") && DEVMODE) {
            return true;
        }

        if (!$ilClientIniFile->variableExists("system", "LANGUAGE_LOG")) {
            return (int) $ilClientIniFile->readVariable("system", "LANGUAGE_LOG") === 1;
        }
        return false;
    }

    /**
     * destructor saves all language usages to db if log is enabled and ilDB exists
     */
    public function __destruct()
    {
        global $DIC;

        //case $ilDB not existing should not happen but if something went wrong it shouldn't leads to any failures
        if (!$this->usage_log_enabled || !$DIC->isDependencyAvailable("database")) {
            return;
        }

        $ilDB = $DIC->database();

        foreach (self::$lng_log as $identifier => $module) {
            $wave[] = "(" . $ilDB->quote($module, "text") . ', ' . $ilDB->quote($identifier, "text") . ")";
            unset(self::$lng_log[$identifier]);

            if (count($wave) === 150 || (count(self::$lng_log) === 0 && count($wave) > 0)) {
                $query = "REPLACE INTO lng_log (module, identifier) VALUES " . implode(", ", $wave);
                $ilDB->manipulate($query);

                $wave = array();
            }
        }
    }
} // END class.Language
