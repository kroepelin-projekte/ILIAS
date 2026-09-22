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

use ILIAS\Language\ComponentTranslation\ComponentLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use Gettext\Generator\MoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;

/**
 * Covers the PO/MO pilot's addition to ilLanguage::loadLanguageModule() (see
 * components/ILIAS/Language/tools/po-migration/README.md): a module whose owning component
 * contributes a LanguageFileDirectory, and that has a compiled .mo file for the requested language,
 * is read from that .mo file instead of lng_modules - everything else falls through unchanged.
 *
 * Uses TermsOfService's real, already-contributed 'tos' directory and its already-compiled .mo
 * files - the pilot script itself only ever produces the .pot/.po files (a real installation
 * compiles the .mo from those, see MigratedLanguageFileSync::sync() and
 * tools/po-migration/README.md) - so this also doubles as a regression test for that contribution
 * staying wired up.
 */
class PoMigrationLoadLanguageModuleTest extends ilLanguageBaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }

        // loadFromMigratedLanguageFile()'s cache is a static property (shared across every
        // ilLanguage instance for the rest of the PHP process, see class.ilLanguage.php) so that
        // _lookupEntry() - itself static - doesn't re-parse a .mo file on every call. Within one
        // PHPUnit run, all test methods share that same process, so it must be reset here or a
        // result cached by one test (or a real page load) would silently leak into the next.
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_translations_cache')->setValue(null, []);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }

        // Only remove a .mo file this test itself compiled - never one that already existed (e.g.
        // from a real local installation of 'de') - see ensureRealTosDeMoFileExists()'s docblock.
        if ($this->created_real_tos_mo !== null && is_file($this->created_real_tos_mo)) {
            unlink($this->created_real_tos_mo);
        }
        $this->created_real_tos_mo = null;

        parent::tearDown();
    }

    private ?string $fixture_directory = null;
    private ?string $created_real_tos_mo = null;

    /**
     * The pilot script (convert_module_to_po.php) only ever produces .pot/.po - the compiled .mo is a
     * runtime artifact created when a language is installed (see MigratedLanguageFileSync::sync() and
     * tools/po-migration/README.md), not something shipped in the repository. The tests below read
     * TermsOfService's real, already-contributed 'tos' directory directly (not through the
     * install/Setup machinery), so they compile tos_de.mo themselves here - exactly mirroring what a
     * real installation of 'de' would produce from the real tos_de.po - and clean it back up in
     * tearDown() so the repository is left without a shipped .mo, matching the new architecture.
     */
    private function ensureRealTosDeMoFileExists(): void
    {
        $lang_dir = ILIAS_ABSOLUTE_PATH . '/components/ILIAS/TermsOfService/lang/';
        $mo_path = $lang_dir . 'tos_de.mo';
        if (is_file($mo_path)) {
            return;
        }

        $translations = (new PoLoader())->loadFile($lang_dir . 'tos_de.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $mo_path);
        $this->created_real_tos_mo = $mo_path;
    }

    /**
     * ntxt()'s plural support and the cross-module collision logging both need a migrated module
     * whose .mo content is test-local (not tos's real 17 production keys). Builds a real .mo file
     * under a throwaway directory inside tests/, via a minimal anonymous LanguageFileDirectory
     * pointing at it - same contract ComponentLanguageFileDirectory fulfills for real components,
     * so this exercises the exact same code path in ilLanguage. Cleaned up in tearDown().
     */
    private function contributeFixtureModule(string $module, Translations $translations): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $relative_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/';
        // includeHeaders(true): without it, the Plural-Forms header (and its CLDR formula) isn't
        // written at all - matching what the real conversion script does (see convert_module_to_po.php)
        (new MoGenerator())->includeHeaders(true)->generateFile(
            $translations,
            $this->fixture_directory . '/' . $module . '_de.mo'
        );

        return new class ($module, $relative_path) implements LanguageFileDirectory {
            public function __construct(private string $prefix, private string $path)
            {
            }

            public function getPrefix(): string
            {
                return $this->prefix;
            }

            public function getPath(): string
            {
                return $this->path;
            }

            public function getSuffix(): string
            {
                return '';
            }

            public function isLocal(): bool
            {
                return false;
            }
        };
    }

    private function buildLanguageWithoutRunningConstructor(string $lang_key, ?string $lang_default = null): ilLanguage
    {
        $language = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        $reflected = new ReflectionObject($language);
        $reflected->getProperty('lang_key')->setValue($language, $lang_key);
        $reflected->getProperty('lang_user')->setValue($language, $lang_key);
        $reflected->getProperty('lang_default')->setValue($language, $lang_default ?? $lang_key);

        return $language;
    }

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                ...$contributed
            )
        );
    }

    /**
     * A successful migrated-file match in _lookupEntry() calls the pre-existing
     * ilLanguage::isUsageLogEnabled(), which unconditionally reads $DIC->clientIni() and
     * $DIC->database() before it can return false - needed here only because these tests are the
     * first to ever reach a match inside _lookupEntry(); unrelated to the .mo migration itself.
     */
    private function stubUsageLogDependencies(): void
    {
        $this->setGlobalVariable('ilClientIniFile', $this->createStub(ilIniFile::class));
        $this->setGlobalVariable('ilDB', $this->createStub(ilDBInterface::class));
    }

    private function callLoadFromMigratedLanguageFile(string $module, string $lang_key): ?array
    {
        $method = (new ReflectionClass(ilLanguage::class))->getMethod('loadFromMigratedLanguageFile');

        // static since it's shared with _lookupEntry() - no ilLanguage instance to invoke it on
        return $method->invoke(null, $module, $lang_key);
    }

    public function testFullyIntegratedThroughTheLoadLanguageModuleThatCallersActuallyUse(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->setGlobalVariable('ilDB', $this->createStub(ilDBInterface::class));
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        $language = $this->buildLanguageWithoutRunningConstructor('de');
        $language->loadLanguageModule('tos');

        $this->assertContains('tos', $language->loaded_modules);
        $this->assertSame('Nutzungsvereinbarung', $language->txt('tos_agreement'));
        $this->assertSame(
            'Nutzungsvereinbarung zugestimmt am',
            $language->txt('tos_agree_date')
        );
    }

    public function testReadsAllSeventeenKnownTosKeysForTheReferenceLanguage(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        $language = $this->buildLanguageWithoutRunningConstructor('de');
        $text = $this->callLoadFromMigratedLanguageFile('tos', 'de');

        $this->assertIsArray($text);
        $this->assertCount(17, $text);
        $this->assertArrayHasKey('tos_withdrawal_usr_deletion_desc', $text);
    }

    public function testFallsBackToNullWhenNoComponentContributesThatPrefix(): void
    {
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        // "common" is not contributed by anyone in this test's manager -> caller must fall back to lng_modules
        $this->assertNull($this->callLoadFromMigratedLanguageFile('common', 'de'));
    }

    public function testFallsBackToNullWhenDirectoryIsContributedButHasNoMoFileForThisLanguage(): void
    {
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        $this->assertNull($this->callLoadFromMigratedLanguageFile('tos', 'xx'));
    }

    public function testFallsBackToNullWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        $this->assertNull($this->callLoadFromMigratedLanguageFile('tos', 'de'));
    }

    /**
     * txtlng() reads a topic in a language other than the current session language via
     * _lookupEntry(), a static sibling of loadFromMigratedLanguageFile() that historically only knew
     * about the DB table lng_data. It now shares the same .mo lookup, so a cross-language read of a
     * migrated module's topic must come from the .mo file too - not just same-language reads via the
     * loadLanguageModule()/txt() path already covered above.
     */
    public function testTxtlngReadsTheMigratedMoFileForALanguageOtherThanTheCurrentSessionLanguage(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->stubUsageLogDependencies();
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        // session language is "en"; the requested language ("de") only differs to force the
        // cross-language branch in txtlng() - no ilDB stub needed, the .mo lookup resolves it
        // before _lookupEntry() ever touches $DIC->database()
        $language = $this->buildLanguageWithoutRunningConstructor('en');

        $this->assertSame('Nutzungsvereinbarung', $language->txtlng('tos', 'tos_agreement', 'de'));
    }

    /**
     * txt($topic, $fallbackModule)'s fallback branch also goes through _lookupEntry() when the topic
     * isn't already loaded into $this->text. Proven here with lang_key === lang_default so it takes
     * the "try default language" branch directly, again without ever needing an ilDB stub.
     */
    public function testTxtsFallbackModuleParameterReadsTheMigratedMoFileTooWhenTheTopicIsNotAlreadyLoaded(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->stubUsageLogDependencies();
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        $language = $this->buildLanguageWithoutRunningConstructor('de');

        $this->assertSame('Nutzungsvereinbarung', $language->txt('tos_agreement', 'tos'));
    }

    /**
     * loadLanguageModule() used to check $this->cached_modules (populated in the real constructor
     * from ilCachedLanguage, a whole-language cache of lng_modules rows keyed "translations_<lang>")
     * before ever reaching the .mo path. Since ilCachedLanguage::isActive() is hard-coded to true,
     * cached_modules is populated from lng_modules on every single real ilLanguage instantiation - and
     * a migrated module keeps its lng_modules row on purpose (dual-write, see
     * ilObjLanguage::syncMigratedLanguageFile()), so that old check order meant the .mo file was
     * *never* read via the normal loadLanguageModule()/txt() path in production, only via the
     * reflection-built instances these tests use (which never run the constructor and so never
     * populate cached_modules). Confirmed live in the browser: a value that existed only in tos_de.mo
     * (not in the DB) never appeared on screen.
     *
     * Fixed by checking the .mo file first; cached_modules is now only consulted as a fallback for
     * modules that aren't migrated (or have no .mo for this language) - see
     * testFallsBackToCachedModulesForANonMigratedModule() below for that unchanged behavior.
     */
    public function testTheMigratedMoFileWinsOverStaleCachedModulesFromIlCachedLanguage(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->setGlobalVariable('ilDB', $this->createStub(ilDBInterface::class));
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        $language = $this->buildLanguageWithoutRunningConstructor('de');
        (new ReflectionObject($language))->getProperty('cached_modules')->setValue(
            $language,
            ['tos' => ['tos_agreement' => 'STALE PRE-MIGRATION TEXT FROM lng_modules CACHE']]
        );

        $language->loadLanguageModule('tos');

        $this->assertSame('Nutzungsvereinbarung', $language->txt('tos_agreement'));
    }

    /**
     * The flip side of the test above: a module that is *not* migrated (no contributed
     * LanguageFileDirectory, or none with a .mo for this language) must still be served from
     * cached_modules exactly as before this fix - only the check order changed, not the fallback
     * itself.
     */
    public function testFallsBackToCachedModulesForANonMigratedModule(): void
    {
        $this->setGlobalVariable('ilDB', $this->createStub(ilDBInterface::class));

        $language = $this->buildLanguageWithoutRunningConstructor('de');
        (new ReflectionObject($language))->getProperty('cached_modules')->setValue(
            $language,
            ['not_migrated' => ['some_topic' => 'From the whole-language DB cache']]
        );

        $language->loadLanguageModule('not_migrated');

        $this->assertSame('From the whole-language DB cache', $language->txt('some_topic'));
    }

    /**
     * ntxt() (FR "PO-Files for improving language handling", 2.2): the counterpart to ngettext() -
     * given a count, the correct plural form is selected via the target language's CLDR plural
     * formula, without the caller having to know that formula. German has 2 forms (singular for
     * n==1, plural otherwise); proven with both n=1 and n=5 against the same migrated fixture module.
     */
    public function testNtxtSelectsTheCorrectPluralFormUsingTheLanguagesCldrFormula(): void
    {
        $translations = Translations::create('demo', 'de');
        $translations->getHeaders()->setPluralForm(2, 'n != 1');
        $translation = Translation::create('demo', 'cart_items');
        $translation->setPlural('cart_items_plural');
        // msgstr[0] (n==1) comes from translate(); translatePlural()'s arguments are msgstr[1..] only
        $translation->translate('Ein Artikel');
        $translation->translatePlural('Mehrere Artikel');
        $translations->add($translation);

        $this->registerDirectoryManager($this->contributeFixtureModule('demo', $translations));
        $language = $this->buildLanguageWithoutRunningConstructor('de');

        $this->assertSame('Ein Artikel', $language->ntxt('demo', 'cart_items', 'cart_items_plural', 1));
        $this->assertSame('Mehrere Artikel', $language->ntxt('demo', 'cart_items', 'cart_items_plural', 5));
    }

    /**
     * There is no legacy lng_data equivalent for plural data (that absence is exactly what this FR
     * entry addresses), so an unresolved ntxt() call has nothing sensible to fall back to. It mirrors
     * txt()'s own "not found" placeholder instead of e.g. silently returning $a_topic verbatim.
     */
    public function testNtxtReturnsThePlaceholderWhenTheModuleIsNotMigratedOrHasNoPluralForThisTopic(): void
    {
        $language = $this->buildLanguageWithoutRunningConstructor('de');

        $this->assertSame('-cart_items-', $language->ntxt('demo', 'cart_items', 'cart_items_plural', 5));
    }

    /**
     * Cross-module identifier collisions (FR "PO-Files for improving language handling", 2.4): two
     * modules independently defining the same identifier used to overwrite each other silently in
     * $this->text. Full structural exclusion isn't possible without changing txt()'s signature (see
     * logCrossModuleKeyCollisions() docblock), but the collision is now at least logged instead of
     * disappearing - proven here with two fixture modules that both define "shared_key".
     */
    public function testLogsACrossModuleKeyCollisionInsteadOfSilentlyOverwritingIt(): void
    {
        $alpha = Translations::create('alpha', 'de');
        $alpha->add(Translation::create('alpha', 'shared_key')->translate('Value from alpha'));

        $beta = Translations::create('beta', 'de');
        $beta->add(Translation::create('beta', 'shared_key')->translate('Value from beta'));

        $this->setGlobalVariable('ilDB', $this->createStub(ilDBInterface::class));
        $this->registerDirectoryManager(
            $this->contributeFixtureModule('alpha', $alpha),
            $this->contributeFixtureModule('beta', $beta)
        );

        $logger = $this->createMock(ilLogger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('shared_key'));

        $language = $this->buildLanguageWithoutRunningConstructor('de');
        (new ReflectionObject($language))->getProperty('log')->setValue($language, $logger);

        $language->loadLanguageModule('alpha');
        $language->loadLanguageModule('beta');

        $this->assertSame('Value from beta', $language->txt('shared_key'));
    }
}
