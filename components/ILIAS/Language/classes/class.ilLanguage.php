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
use Gettext\Loader\MoLoader;
use Gettext\Translations;
use Gettext\Translator;

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
 * Two coexisting backends per module: the legacy DB tables lng_data/lng_modules (still the
 * fallback for every not-yet-migrated module, and read by default), and - for a module that
 * contributed a LanguageFileDirectory and has a compiled overlay .mo for the requested language -
 * a file-based overlay under CLIENT_DATA_DIR (see loadFromMigratedLanguageFile() below), which then
 * takes priority over the DB. The DB path exists to be fully replaced and eventually removed as
 * more modules migrate onto the file-based one, not to be maintained forever alongside it.
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
     * Per-request cache for loadFromMigratedLanguageFile(), keyed "<module>|<lang_key>". Static
     * (not per-instance) because _lookupEntry() - used by txtlng() and txt()'s fallback-module
     * branch - is itself static and has no ilLanguage instance to cache on; re-parsing the same
     * .mo file on every txtlng() call would otherwise be wasteful. Safe across requests because
     * PHP resets static state between requests; only needs resetting between test methods that run
     * in the same process, which ilLanguageBaseTestCase-based tests do via reflection.
     */
    private static array $migrated_language_file_cache = [];

    /**
     * Per-request cache of the raw Gettext\Translations for a migrated module+language, keyed the
     * same way as $migrated_language_file_cache. Kept separate from that flat-array cache so the
     * already-tested singular lookup path doesn't change at all; used only by ntxt() (plural lookups,
     * see FR "PO-Files for improving language handling", 2.2) where the plural forms and the CLDR
     * plural formula are needed, not just a flat topic => value map.
     */
    private static array $migrated_translations_cache = [];

    /**
     * Tracks, for topics loaded via the migrated .mo path (not the legacy lng_modules path), which
     * module last supplied a given topic - independent of $map_modules_txt, which is only populated
     * when usage logging is enabled and serves a different purpose. Used solely to detect and log
     * cross-module identifier collisions (FR "PO-Files for improving language handling", 2.4)
     * instead of silently overwriting one module's value with another's during the array_merge()
     * into $this->text.
     */
    protected array $migrated_topic_modules = array();
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

        $translation = $this->text[$a_topic] ?? "";

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
     * Check if language entry exists
     */
    public function exists(string $a_topic): bool
    {
        return isset($this->text[$a_topic]);
    }

    /**
     * Load language module
     */
    public function loadLanguageModule(string $a_module): void
    {
        global $DIC;
        $ilDB = $DIC->database();

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

        // PO/MO pilot: a module whose owning component contributes a LanguageFileDirectory for
        // $a_module, and that actually has a compiled .mo file for $lang_key sitting there, is read
        // from that .mo instead of lng_modules.
        // Modules that haven't been migrated yet (no contribution, or no .mo present for this
        // language) fall through to the unchanged DB/cache path below. This check must run before the
        // cached_modules check: ilCachedLanguage::isActive() is hard-coded to true, so cached_modules
        // is always populated from lng_modules for every module that still has a DB row there - which
        // migrated modules do on purpose (dual-write, see ilObjLanguage::syncMigratedLanguageFile()).
        // Checking cached_modules first would therefore always win and the .mo file would never be read.
        $mo_text = self::loadFromMigratedLanguageFile($a_module, $lang_key);
        if ($mo_text !== null) {
            $this->logCrossModuleKeyCollisions($a_module, $mo_text);
            $this->text = array_merge($this->text, $mo_text);

            foreach (array_keys($mo_text) as $key) {
                $this->migrated_topic_modules[$key] = $a_module;
            }

            if ($this->usage_log_enabled) {
                foreach (array_keys($mo_text) as $key) {
                    $this->map_modules_txt[$key] = $a_module;
                }
            }

            return;
        }

        if (isset($this->cached_modules[$a_module]) && is_array($this->cached_modules[$a_module])) {
            $this->text = array_merge($this->text, $this->cached_modules[$a_module]);

            if ($this->usage_log_enabled) {
                foreach (array_keys($this->cached_modules[$a_module]) as $key) {
                    $this->map_modules_txt[$key] = $a_module;
                }
            }

            return;
        }

        $q = "SELECT lang_array FROM lng_modules " .
                "WHERE lang_key = " . $ilDB->quote($lang_key, "text") . " AND module = " .
                $ilDB->quote($a_module, "text");
        $r = $ilDB->query($q);
        $row = $r->fetchRow(ilDBConstants::FETCHMODE_ASSOC);

        if ($row === false) {
            return;
        }

        $new_text = unserialize($row["lang_array"], ["allowed_classes" => false]);
        if (is_array($new_text)) {
            $this->text = array_merge($this->text, $new_text);

            if ($this->usage_log_enabled) {
                foreach (array_keys($new_text) as $key) {
                    $this->map_modules_txt[$key] = $a_module;
                }
            }
        }
    }

    /**
     * FR "PO-Files for improving language handling" (2.4) wants cross-module identifier collisions
     * to stop being silently overwritten. Fully eliminating them for existing txt($topic) callers
     * isn't possible without changing that signature - which the same FR (2.5) explicitly rules out
     * for existing callers - since txt() has no way to know which module's value it means when two
     * modules independently pick the same identifier. Short of that signature change, this turns
     * "silently overwritten" into "logged", for topics whose *previous* value also came from an
     * already-loaded migrated module (collisions against the legacy lng_modules path aren't tracked
     * here, since that path stores no per-topic module attribution to compare against).
     */
    private function logCrossModuleKeyCollisions(string $a_module, array $mo_text): void
    {
        foreach ($mo_text as $topic => $value) {
            $existing_module = $this->migrated_topic_modules[$topic] ?? null;
            if (
                $existing_module !== null
                && $existing_module !== $a_module
                && ($this->text[$topic] ?? null) !== $value
            ) {
                $this->log->warning(sprintf(
                    'Language key collision: identifier "%s" is defined by both module "%s" and'
                    . ' module "%s" - the value from "%s" now wins for txt("%s").',
                    $topic,
                    $existing_module,
                    $a_module,
                    $a_module,
                    $topic
                ));
            }
        }
    }

    /**
     * Returns $a_module's translations for $lang_key as a flat topic => value array, read from a
     * compiled .mo file, if (and only if) $a_module's owning component contributes a
     * LanguageFileDirectory for it (see components/ILIAS/Language/src/ComponentTranslation/) and that
     * directory actually contains a "<module>_<lang_key>.mo" file. Returns null in every other case -
     * including "directory contributed but no .mo there yet" - so the caller can fall back to
     * lng_modules without having to know why.
     *
     * Static so _lookupEntry() (itself static, used by txtlng() and txt()'s fallback-module branch)
     * can share it with loadLanguageModule() instead of duplicating the lookup. Result is cached per
     * "<module>|<lang_key>" for the rest of the request, since _lookupEntry() may be called once per
     * topic rather than once per module.
     */
    /**
     * Invalidates the two per-request caches above for one module+language, so a write that just
     * happened (see ilObjLanguage::syncMigratedLanguageFile(), called from replaceLangModule()) is
     * visible to the rest of the same request instead of serving what was cached before the write.
     * Public: the writer lives in a different class (ilObjLanguage) that has no other access to
     * these private static caches.
     */
    public static function invalidateMigratedLanguageFileCache(string $a_module, string $lang_key): void
    {
        $cache_key = $a_module . '|' . $lang_key;
        unset(self::$migrated_language_file_cache[$cache_key]);
        unset(self::$migrated_translations_cache[$cache_key]);
    }

    private static function loadFromMigratedLanguageFile(string $a_module, string $lang_key): ?array
    {
        $cache_key = $a_module . '|' . $lang_key;
        if (array_key_exists($cache_key, self::$migrated_language_file_cache)) {
            return self::$migrated_language_file_cache[$cache_key];
        }

        $mo_file = self::migratedOverlayMoFile($a_module, $lang_key);
        if ($mo_file === null || !is_file($mo_file)) {
            return self::$migrated_language_file_cache[$cache_key] = null;
        }

        $translations = (new MoLoader())->loadFile($mo_file);
        $text = [];
        foreach ($translations->getTranslations() as $translation) {
            $text[$translation->getOriginal()] = $translation->getTranslation() ?? '';
        }

        return self::$migrated_language_file_cache[$cache_key] = $text;
    }

    /**
     * Same resolution as loadFromMigratedLanguageFile() (contributed directory + existing .mo file),
     * but returns the raw Gettext\Translations instead of a flattened topic => value array, so ntxt()
     * can reach the plural forms and the Plural-Forms header that a flat array would throw away.
     * Deliberately does not share loadFromMigratedLanguageFile()'s cache/code so that method - and
     * every test covering it - stays exactly as it was before plural support was added.
     */
    private static function loadMigratedTranslations(string $a_module, string $lang_key): ?Translations
    {
        $cache_key = $a_module . '|' . $lang_key;
        if (array_key_exists($cache_key, self::$migrated_translations_cache)) {
            return self::$migrated_translations_cache[$cache_key];
        }

        $mo_file = self::migratedOverlayMoFile($a_module, $lang_key);
        if ($mo_file === null || !is_file($mo_file)) {
            return self::$migrated_translations_cache[$cache_key] = null;
        }

        $translations = (new MoLoader())->loadFile($mo_file);
        // MO is a pure key/value binary format with no dedicated domain field; Translations::getDomain()
        // only round-trips via the (optional, header-block) X-Domain entry, so it cannot be relied on.
        // Translator::createFromTranslations() indexes its dictionary by exactly this domain, and $a_module
        // is already authoritative (it is how this .mo file was found), so set it explicitly rather than
        // depend on the file having kept a header that may not even have been written.
        $translations->setDomain($a_module);

        return self::$migrated_translations_cache[$cache_key] = $translations;
    }

    /**
     * Quantity-dependent counterpart to txt(), matching what the FR "PO-Files for improving language
     * handling" (2.2) calls the wrapper around ngettext(): the caller passes only the count, and the
     * correct plural form is picked automatically using the target language's CLDR plural formula
     * (evaluated by Gettext\Translator, not hand-rolled here - plural rules are genuinely intricate
     * for languages with 3-6 forms, and this is the same vetted parser the vendored gettext/translator
     * package already ships).
     *
     * Only usable for a migrated module that actually has a plural pair for $a_topic - there is no
     * legacy lng_data/lng_modules fallback, because the old scheme has no structured plural data to
     * fall back to (that lack is exactly what this FR entry addresses). Returns the same "-topic-"
     * placeholder txt() uses for an unresolved topic, for a consistent "not found" signal.
     */
    public function ntxt(string $a_module, string $a_topic, string $a_topic_plural, int $a_num): string
    {
        $translations = self::loadMigratedTranslations($a_module, $this->lang_key);
        if ($translations === null) {
            return "-" . $a_topic . "-";
        }

        $translation = $translations->find($a_module, $a_topic);
        if ($translation === null || implode('', $translation->getPluralTranslations()) === '') {
            return "-" . $a_topic . "-";
        }

        if ($this->usage_log_enabled) {
            $this->map_modules_txt[$a_topic] = $a_module;
            self::logUsage($a_module, $a_topic);
        }

        return Translator::createFromTranslations($translations)->dnpgettext(
            $a_module,
            $translation->getContext() ?? '',
            $a_topic,
            $a_topic_plural,
            $a_num
        );
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
     * The read-side counterpart to MigratedLanguageFileSync's overlay path (see that class' docblock): a
     * migrated module's compiled `.mo` lives under CLIENT_DATA_DIR, never in the git-tracked component
     * tree the shipped `.po` ships in - so this, not ILIAS_ABSOLUTE_PATH, is what txt()/ntxt() actually
     * read from at runtime. Returns `null` (never falls back to the shipped path) when the module
     * hasn't contributed a LanguageFileDirectory, or CLIENT_DATA_DIR isn't defined yet - the latter is
     * only ever true before ilInitialisation::initClientDataDir() has run, i.e. never for a fully
     * bootstrapped request that could reach txt() in the first place.
     */
    private static function migratedOverlayMoFile(string $a_module, string $lang_key): ?string
    {
        if (!defined('CLIENT_DATA_DIR')) {
            return null;
        }

        $directory = self::findLanguageFileDirectory($a_module);
        if ($directory === null) {
            return null;
        }

        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/' . ltrim($directory->getPath(), '/')
            . $a_module . '_' . $lang_key . '.mo';
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
        // PO/MO pilot: same migrated .mo lookup as loadLanguageModule(), extended to this static,
        // DB-based (lng_data) sibling
        // used by txtlng() and txt()'s fallback-module branch. Falls through to lng_data unchanged
        // when the module isn't migrated, has no .mo for $a_lang_key, or doesn't have this $a_id.
        $migrated = self::loadFromMigratedLanguageFile($a_mod, $a_lang_key);
        if ($migrated !== null && isset($migrated[$a_id]) && $migrated[$a_id] !== '') {
            self::$used_topics[$a_id] = $a_id;
            self::$used_modules[$a_mod] = $a_mod;

            if (self::isUsageLogEnabled()) {
                self::logUsage($a_mod, $a_id);
            }

            return $migrated[$a_id];
        }

        global $DIC;
        $ilDB = $DIC->database();

        $set = $ilDB->query($q = sprintf(
            "SELECT value FROM lng_data WHERE module = %s " .
            "AND lang_key = %s AND identifier = %s",
            $ilDB->quote($a_mod, "text"),
            $ilDB->quote($a_lang_key, "text"),
            $ilDB->quote($a_id, "text")
        ));
        $rec = $ilDB->fetchAssoc($set);

        if (isset($rec["value"]) && $rec["value"] != "") {
            // remember the used topics
            self::$used_topics[$a_id] = $a_id;
            self::$used_modules[$a_mod] = $a_mod;

            if (self::isUsageLogEnabled()) {
                self::logUsage($a_mod, $a_id);
            }

            return $rec["value"];
        }

        return "-" . $a_id . "-";
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
                $a_tpl->addOnloadCode("il.Language.setLangVar('" . $k . "', " . json_encode($v, JSON_THROW_ON_ERROR) . ");");
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
