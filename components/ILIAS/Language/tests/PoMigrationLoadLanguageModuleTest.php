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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Covers the PO/MO pilot's addition to ilLanguage::loadLanguageModule(): a module whose owning
 * component contributes a LanguageFileDirectory, and that has a compiled .mo file for the requested
 * language, is read from that .mo file instead of lng_modules - everything else falls through
 * unchanged.
 *
 * Uses TermsOfService's real, already-contributed 'tos' directory and its already-compiled .mo
 * files - the pilot script itself only ever produces the .pot/.po files (a real installation
 * compiles the .mo from those, see MigratedLanguageFileSync::sync()) - so this also doubles as a
 * regression test for that contribution staying wired up.
 *
 * Runs every test method in its own separate process: a full-suite run can have CLIENT_DATA_DIR
 * already defined by an earlier, unrelated test class sharing the same process (e.g.
 * Filesystem/tests/ilServicesFileSystemTest.php or Test/tests/ilTestBaseTestCaseTrait.php define it
 * as /var/iliasdata) - without this, MigratedPoFixture::ensureClientDataDirDefinedOrSkip()'s guard
 * would then skip every single test below for the rest of that process, since a PHP constant cannot
 * be redefined. A fresh process per test method guarantees CLIENT_DATA_DIR starts out undefined here,
 * exactly like a lone test run.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PoMigrationLoadLanguageModuleTest extends ilLanguageBaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
        // ilLanguage::migratedOverlayMoFile() resolves a migrated module's compiled `.mo` under
        // CLIENT_DATA_DIR - never under ILIAS_ABSOLUTE_PATH. A PHP constant cannot be redefined, so
        // this is guarded exactly like ILIAS_ABSOLUTE_PATH above.
        //
        // Deliberately NOT resolved/defined here already: ensureRealTosDeMoFileExists() and
        // contributeFixtureModule() below are the places that actually write fixture files under
        // CLIENT_DATA_DIR, so they (not setUp()) are where a foreign, non-temp CLIENT_DATA_DIR from an
        // earlier test in a full-suite run must skip rather than write there (see
        // MigratedPoFixture::ensureClientDataDirDefinedOrSkip()), and where
        // testRefusesToWriteIntoAClientDataDirOutsideSysTempDir() below needs it to still be undefined
        // when its own test body runs.

        // loadFromMigratedLanguageFile()'s cache is a static property (shared across every
        // ilLanguage instance for the rest of the PHP process, see class.ilLanguage.php) so that
        // _lookupEntry() - itself static - doesn't re-parse a .mo file on every call. Within one
        // PHPUnit run, all test methods share that same process, so it must be reset here or a
        // result cached by one test (or a real page load) would silently leak into the next.
        MigratedPoFixture::resetRuntime();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }
        if (isset($this->fixture_directory)) {
            MigratedPoFixture::removeShippedDirectory('components/ILIAS/Language/tests/' . basename($this->fixture_directory));
        }

        // Only remove a .mo file this test itself compiled - never one that already existed (e.g.
        // from a real local installation of 'de') - see ensureRealTosDeMoFileExists()'s docblock.
        if ($this->created_real_tos_mo !== null && is_file($this->created_real_tos_mo)) {
            unlink($this->created_real_tos_mo);
            $overlay_dir = dirname($this->created_real_tos_mo);
            if (is_dir($overlay_dir) && glob($overlay_dir . '/*') === []) {
                rmdir($overlay_dir);
            }
        }
        $this->created_real_tos_mo = null;

        // Only this test's own, freshly generated CLIENT_DATA_DIR root is removed here - never a
        // pre-existing, foreign one (see ensureClientDataDirDefinedOrSkip()'s own docblock and
        // $created_client_data_dir_root's).
        if ($this->created_client_data_dir_root && defined('CLIENT_DATA_DIR') && is_dir(CLIENT_DATA_DIR)) {
            MigratedPoFixture::removeDirectory(CLIENT_DATA_DIR);
        }

        parent::tearDown();
    }

    private ?string $fixture_directory = null;
    private ?string $created_real_tos_mo = null;
    /**
     * True exactly when this test's own call to MigratedPoFixture::ensureClientDataDirDefinedOrSkip()
     * defined CLIENT_DATA_DIR itself (as opposed to a pre-existing, foreign definition it merely
     * reused) - see tearDown(). With every test running in its own process (see this class' own
     * #[RunTestsInSeparateProcesses]), this is true for every ordinary test run, so the freshly
     * generated, uniquely-named root this test created is always cleaned up again instead of
     * accumulating one leftover temp directory per test method.
     */
    private bool $created_client_data_dir_root = false;

    /**
     * The pilot script (convert_module_to_po.php) only ever produces .pot/.po - the compiled .mo is a
     * runtime artifact created when a language is installed (see MigratedLanguageFileSync::sync()),
     * not something shipped in the repository. The tests below read
     * TermsOfService's real, already-contributed 'tos' directory directly (not through the
     * install/Setup machinery), so they compile tos_de.mo themselves here - exactly mirroring what a
     * real installation of 'de' would produce from the real (shipped, git-tracked) tos_de.po, but at
     * the OVERLAY location under CLIENT_DATA_DIR, which is what ilLanguage::migratedOverlayMoFile()
     * actually reads at runtime - never the shipped, git-tracked directory itself (see that method's
     * docblock).
     * Cleaned back up in tearDown() so no test ever leaves a compiled artifact behind, under either
     * location.
     */
    private function ensureRealTosDeMoFileExists(): void
    {
        $created = MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);
        $this->created_client_data_dir_root = $this->created_client_data_dir_root || $created;
        // the shipped state is served from the build (see buildLanguageWithoutRunningConstructor()
        // and callLoadFromMigratedLanguageFile()), no overlay is needed
    }

    /**
     * The corrupt-.mo fallback and the cross-module collision logging both need a migrated module
     * whose .mo content is test-local (not tos's real 17 production keys). Builds a real .mo file
     * directly at the OVERLAY location under CLIENT_DATA_DIR - since ilLanguage::migratedOverlayMoFile()
     * only ever reads there, never under the git-tracked tests/ directory - via a minimal anonymous
     * LanguageFileDirectory pointing at it, same contract ComponentLanguageFileDirectory fulfills for
     * real components, so this exercises the exact same code path in ilLanguage. Cleaned up in
     * tearDown().
     */
    private function contributeFixtureModule(string $module, \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog $translations): LanguageFileDirectory
    {
        $created = MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);
        $this->created_client_data_dir_root = $this->created_client_data_dir_root || $created;
        $this->fixture_directory ??= __DIR__ . '/tmp-fixtures-' . bin2hex(random_bytes(4));

        $relative_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/';
        MigratedPoFixture::writeShippedPo($relative_path, $module, 'de', $translations);

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

    /**
     * contributeFixtureModule() with independent content for the shipped `.po` and the overlay `.mo`
     * - needed to prove the array_replace() merge of readMigratedLanguageFile(), rather than only
     * "the overlay wins for an identifier both sides have" (see testACorruptOverlayMoFallsBackToTheDatabaseAndWarnsOnce()
     * and the "STALE"/"servus" scenarios above, which never exercise a key that exists on only one side).
     */
    private function contributeFixtureModuleWithDistinctShippedAndOverlay(
        string $module,
        \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog $shipped,
        \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog $overlay
    ): LanguageFileDirectory {
        $created = MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);
        $this->created_client_data_dir_root = $this->created_client_data_dir_root || $created;
        $this->fixture_directory ??= __DIR__ . '/tmp-fixtures-' . bin2hex(random_bytes(4));

        $relative_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/';
        MigratedPoFixture::writePair(rtrim(CLIENT_DATA_DIR, '/') . '/lang/' . $module . '/de/' . $module . '_de', $overlay);
        MigratedPoFixture::writeShippedPo($relative_path, $module, 'de', $shipped);

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

    /**
     * The array_replace($shipped, $overlay) merge of readMigratedLanguageFile(): a key that exists
     * ONLY in the overlay is added (an AddLanguageEntry-style local addition, absent from the shipped
     * .po), a key that exists ONLY in the shipped state is kept (not dropped merely because the
     * overlay does not mention it), and a key both sides have is won by the overlay - all three at
     * once, in a single merged result.
     */
    public function testMergesTheOverlayOntoTheShippedStateKeepingKeysUniqueToEitherSide(): void
    {
        $shipped = MigratedPoFixture::catalog('merge', [
            'only_shipped' => 'Nur im Shipped-Stand',
            'both' => 'Shipped-Wert',
        ]);
        $overlay = MigratedPoFixture::catalog('merge', [
            'only_overlay' => 'Nur im Overlay',
            'both' => 'Lokal geändert',
        ]);
        $directory = $this->contributeFixtureModuleWithDistinctShippedAndOverlay('merge', $shipped, $overlay);
        $this->setGlobalVariable('ilDB', $this->createStub(ilDBInterface::class));
        $this->registerDirectoryManager($directory);

        $result = $this->callLoadFromMigratedLanguageFile('merge', 'de');

        $this->assertSame('Nur im Shipped-Stand', $result['only_shipped'] ?? null);
        $this->assertSame('Lokal geändert', $result['both'] ?? null);
        $this->assertSame('Nur im Overlay', $result['only_overlay'] ?? null);
        $this->assertCount(3, $result);
    }

    private function buildLanguageWithoutRunningConstructor(string $lang_key, ?string $lang_default = null): ilLanguage
    {
        global $DIC;
        // the runtime serves the build (see MigratedTranslations)
        if ($DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            MigratedPoFixture::build($DIC[LanguageFileDirectoryManager::class], (string) ILIAS_ABSOLUTE_PATH);
        }
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

    /**
     * What the runtime serves for $module/$lang_key, see MigratedPoFixture::servedTexts().
     */
    private function callLoadFromMigratedLanguageFile(string $module, string $lang_key): ?array
    {
        return MigratedPoFixture::servedTexts($module, $lang_key);
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

    /**
     * Naming note: under the current shipped+overlay design "migrated for a language" means "has a
     * shipped `.po` for it" (see ShippedTranslations/readMigratedLanguageFile()) - it is the absence
     * of a shipped `tos_xx.po` that makes this null, not the absence of an overlay `.mo`.
     */
    public function testFallsBackToNullWhenDirectoryIsContributedButHasNoShippedPoForThisLanguage(): void
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
     * M3: migratedOverlayMoFile() builds shipped/overlay paths via MigratedLanguageFilePaths, which
     * validates $lang_key strictly (exactly two lowercase letters) and throws \InvalidArgumentException
     * for anything else - path traversal ("../x") included. That exception is caught: an invalid
     * $lang_key is simply never migrated, the same as one with no `.mo` file for it - never a fatal
     * error, since $a_lang_key/$a_id reach here as untrusted, quoted-for-SQL input via _lookupEntry()
     * (a public method), not only from trusted, already-validated language keys.
     *
     * @param non-empty-string $invalid_lang_key
     */
    #[DataProvider('invalidLangKeys')]
    public function testFallsBackToNullForAnInvalidLangKeyInsteadOfThrowing(string $invalid_lang_key): void
    {
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );

        $this->assertNull($this->callLoadFromMigratedLanguageFile('tos', $invalid_lang_key));
    }

    /**
     * M3: _lookupEntry() (txtlng()'s and txt()'s fallback-module branch's underlying static) must
     * keep working for an invalid $a_lang_key exactly as it always did before the PO/MO pilot - by
     * falling through to the lng_data database lookup - not throw a caller-visible
     * \InvalidArgumentException merely because the .mo-file short-cut it now also attempts first
     * cannot be taken.
     *
     * @param non-empty-string $invalid_lang_key
     */
    #[DataProvider('invalidLangKeys')]
    public function testLookupEntryFallsBackToTheDatabaseForAnInvalidLangKey(string $invalid_lang_key): void
    {
        $this->registerDirectoryManager(
            new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos')
        );
        $this->stubUsageLogDependencies();
        $statement = $this->createStub(ilDBStatement::class);
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn(mixed $value): string => "'" . (string) $value . "'");
        $db->method('query')->willReturn($statement);
        $db->method('fetchAssoc')->willReturn(['value' => 'from the database']);
        $this->setGlobalVariable('ilDB', $db);

        $this->assertSame(
            'from the database',
            ilLanguage::_lookupEntry($invalid_lang_key, 'tos', 'sometopic')
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidLangKeys(): array
    {
        return [
            'path traversal' => ['../x'],
            'uppercase' => ['DE'],
            'locale-style with region' => ['de_DE'],
            'empty' => [''],
        ];
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





    private static function truncate(string $strategy, string $mo): string
    {
        return match ($strategy) {
            'first_10_bytes' => substr($mo, 0, 10),
            'first_40_bytes' => substr($mo, 0, 40),
            'first_half' => substr($mo, 0, intdiv(strlen($mo), 2)),
            'all_but_last_3_bytes' => substr($mo, 0, -3),
            'empty' => '',
            default => throw new \LogicException('Unknown truncation strategy "' . $strategy . '".'),
        };
    }

    // ------------------------------------------------- installed-languages restriction

    /**
     * Mirrors what ilLanguage's constructor stores (see its own docblock) - the reflection-based
     * shortcut every test below uses instead of actually constructing an ilLanguage (which pulls in
     * $DIC->clientIni()/settings()/user() etc. unrelated to this concern).
     *
     * @param list<string> $lang_keys
     */
    private function setInstalledLanguages(array $lang_keys): void
    {
        (new ReflectionProperty(ilLanguage::class, 'installed_languages'))->setValue(null, $lang_keys);
    }

    /**
     * The default/normal case: the language being read is in the installed-languages list the
     * (real) constructor determined - the migrated state (shipped + overlay) is served exactly as
     * for every other test in this file that never touches this mechanism at all (see the "without
     * any known list" test below for why those still pass).
     */
    public function testMigratedStateIsServedForAnInstalledLanguage(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->registerDirectoryManager(new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos'));
        $this->setInstalledLanguages(['de', 'en']);

        $this->assertSame(
            'Nutzungsvereinbarung',
            $this->callLoadFromMigratedLanguageFile('tos', 'de')['tos_agreement']
        );
    }

    /**
     * A language that is NOT in the installed-languages list falls back to lng_modules exactly like a
     * module that isn't migrated at all - even though its shipped `.po` and a compiled overlay both
     * exist and are perfectly readable.
     */
    public function testMigratedStateFallsBackToNullForALanguageNotInTheInstalledLanguagesList(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->registerDirectoryManager(new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos'));
        $this->setInstalledLanguages(['en', 'fr']);

        $this->assertNull($this->callLoadFromMigratedLanguageFile('tos', 'de'));
    }

    /**
     * The restriction applies through _lookupEntry() (txtlng()'s and txt()'s fallback-module branch's
     * underlying static) too - not only the directly-invoked loadFromMigratedLanguageFile(): a
     * not-installed language falls through to the database lookup exactly like an invalid lang_key
     * does (see testLookupEntryFallsBackToTheDatabaseForAnInvalidLangKey()).
     */
    public function testTxtlngFallsBackToTheDatabaseForALanguageNotInTheInstalledLanguagesList(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->registerDirectoryManager(new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos'));
        $this->setInstalledLanguages(['en']);
        $this->stubUsageLogDependencies();
        $statement = $this->createStub(ilDBStatement::class);
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn(mixed $value): string => "'" . (string) $value . "'");
        $db->method('query')->willReturn($statement);
        $db->method('fetchAssoc')->willReturn(['value' => 'from the database']);
        $this->setGlobalVariable('ilDB', $db);

        $language = $this->buildLanguageWithoutRunningConstructor('en');

        $this->assertSame('from the database', $language->txtlng('tos', 'tos_agreement', 'de'));
    }

    /**
     * forgetInstalledLanguage() takes effect for the REST OF THE SAME REQUEST: it both removes the
     * language from the list (so a not-yet-cached read now falls back too) and drops whatever was
     * already cached for it, so a stale cached hit from before the uninstall cannot leak through.
     */
    public function testForgetInstalledLanguageStopsServingTheMigratedStateWithinTheSameRequest(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->registerDirectoryManager(new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos'));
        $this->setInstalledLanguages(['de']);
        // populates the per-module cache while "de" is still installed
        $before = $this->callLoadFromMigratedLanguageFile('tos', 'de');
        $this->assertSame('Nutzungsvereinbarung', $before['tos_agreement']);

        ilLanguage::forgetInstalledLanguage('de');

        $this->assertNull(
            $this->callLoadFromMigratedLanguageFile('tos', 'de'),
            'both the stale cache entry and the list membership must be gone'
        );
    }

    /**
     * Without any known list at all (no ilLanguage was constructed in this request - the default
     * starting point of every other test in this file) nothing is restricted; this is what makes
     * every other, unrelated test in this file keep passing without ever calling
     * setInstalledLanguages() itself.
     */
    public function testWithoutAnyKnownInstalledLanguagesListNoRestrictionApplies(): void
    {
        $this->ensureRealTosDeMoFileExists();
        $this->registerDirectoryManager(new ComponentLanguageFileDirectory(new \ILIAS\TermsOfService(), 'tos'));

        $this->assertSame(
            'Nutzungsvereinbarung',
            $this->callLoadFromMigratedLanguageFile('tos', 'de')['tos_agreement']
        );
    }




    /**
     * A CLIENT_DATA_DIR that is not a test-owned temp directory (e.g. the real /var/iliasdata a
     * full-suite run may already have defined, see MigratedPoFixture::ensureClientDataDirDefinedOrSkip())
     * must never be written into by ensureRealTosDeMoFileExists()/contributeFixtureModule() - the test
     * skips instead. Runs in its own process so this class' own tests (which would otherwise define
     * CLIENT_DATA_DIR first in the shared process) cannot pre-empt the "not yet defined" starting point
     * this test needs.
     */
    #[RunInSeparateProcess]
    public function testRefusesToWriteIntoAClientDataDirOutsideSysTempDir(): void
    {
        $foreign_dir = __DIR__ . '/tmp-not-a-temp-dir-' . bin2hex(random_bytes(4));
        $this->assertFalse(str_starts_with($foreign_dir, sys_get_temp_dir() . '/'));
        mkdir($foreign_dir, 0775, true);
        define('CLIENT_DATA_DIR', $foreign_dir);

        try {
            try {
                $this->ensureRealTosDeMoFileExists();
                $this->fail('ensureRealTosDeMoFileExists() must refuse to write into a CLIENT_DATA_DIR outside sys_get_temp_dir().');
            } catch (SkippedWithMessageException $e) {
                $this->assertStringContainsString($foreign_dir, $e->getMessage());
            }

            $this->assertSame([], array_diff(scandir($foreign_dir) ?: [], ['.', '..']));
        } finally {
            if (is_dir($foreign_dir)) {
                array_map('unlink', glob($foreign_dir . '/*') ?: []);
                rmdir($foreign_dir);
            }
        }
    }
}
