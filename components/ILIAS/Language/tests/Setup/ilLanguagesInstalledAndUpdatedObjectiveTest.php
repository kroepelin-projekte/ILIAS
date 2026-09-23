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

use ILIAS\Setup;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Guards the database handling of ilLanguagesInstalledAndUpdatedObjective.
 *
 * ilSetupLanguage resolves its database lazily, so setDbHandler() is
 * authoritative for everything the install path touches. These tests pin
 * both halves of that down, because a regression would be silent - the
 * global fallback would simply take over again.
 */
class ilLanguagesInstalledAndUpdatedObjectiveTest extends TestCase
{
    private bool $had_global_db;
    private mixed $previous_global_db = null;

    protected function setUp(): void
    {
        // These tests deliberately manipulate $GLOBALS['ilDB'] - one replaces
        // it, one removes it - so it has to be restored afterwards. Tests run
        // in random order and other tests in this component do rely on the
        // global.
        $this->had_global_db = isset($GLOBALS['ilDB']);
        $this->previous_global_db = $GLOBALS['ilDB'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->had_global_db) {
            $GLOBALS['ilDB'] = $this->previous_global_db;
        } else {
            unset($GLOBALS['ilDB']);
        }
    }

    /**
     * @param list<string> $log collects the tag of every database actually queried
     */
    private function createDatabaseMock(string $tag, array &$log): ilDBInterface
    {
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn(mixed $value): string => "'" . (string) $value . "'");
        $db->method('like')->willReturn('1=1');
        $db->method('now')->willReturn('NOW()');
        $db->method('nextId')->willReturn(1);
        $db->method('manipulate')->willReturnCallback(static function () use ($tag, &$log): int {
            $log[] = $tag;
            return 1;
        });
        $db->method('query')->willReturnCallback(function () use ($tag, &$log) {
            $log[] = $tag;
            return $this->createStub(ilDBStatement::class);
        });

        // A single installed language, then exhausted. $rows must be captured
        // by reference - a by-value capture would hand out the same row for
        // ever and the "while ($row = fetchObject())" loops would not end.
        $rows = [(object) ['title' => 'de', 'obj_id' => 7, 'description' => 'installed']];
        $db->method('fetchObject')->willReturnCallback(static function () use (&$rows) {
            return array_shift($rows);
        });

        return $db;
    }

    public function testInjectedDatabaseTakesPrecedenceOverTheGlobal(): void
    {
        $log = [];
        $GLOBALS['ilDB'] = $this->createDatabaseMock('GLOBAL', $log);

        $setup_language = new ilSetupLanguage('de');
        $setup_language->setDbHandler($this->createDatabaseMock('INJECTED', $log));

        $setup_language->getInstalledLanguages();
        $setup_language->registerInstalledLanguage('de', [], []);

        $this->assertNotEmpty($log, 'no database was queried at all');
        $this->assertSame(
            ['INJECTED'],
            array_values(array_unique($log)),
            'the global was used even though a database had been injected'
        );
    }

    public function testAchieveNeedsNoGlobalDatabaseAtAll(): void
    {
        $log = [];
        unset($GLOBALS['ilDB']);

        $objective = new ilLanguagesInstalledAndUpdatedObjective(new ilSetupLanguage('en'));
        $environment = new Setup\ArrayEnvironment([
            Setup\Environment::RESOURCE_DATABASE => $this->createDatabaseMock('INJECTED', $log),
        ]);

        $objective->achieve($environment);

        $this->assertFalse(isset($GLOBALS['ilDB']), 'the global database must not be (re)created');
        $this->assertSame(['INJECTED'], array_values(array_unique($log)));
    }

    /**
     * @param list<string> $log collects the language key every write method
     *        was actually called with, in call order
     */
    private function createSetupLanguageMock(array $installed_language_keys, array &$log): Stub&ilSetupLanguage
    {
        $setup_language = $this->createStub(ilSetupLanguage::class);
        $setup_language->method('getInstalledLanguages')->willReturn($installed_language_keys);
        $setup_language->method('getAvailableLanguagesForInstallation')->willReturn([]);
        $setup_language->method('getLocalLanguages')->willReturn([]);
        $setup_language->method('getInvalidLocalLanguageFiles')->willReturn([]);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $setup_language->method('flushLanguageForInstallation')->willReturnCallback(
            static function (string $lang_key) use (&$log): void {
                $log[] = $lang_key;
            }
        );

        return $setup_language;
    }

    /**
     * installLanguages() is protected, so it is invoked here via reflection -
     * the same approach already used by ilSetupLanguageTest::callCheckLanguage()
     * for a protected method on a sibling class in this component.
     *
     * @param list<string> $language_keys
     */
    private function invokeInstallLanguages(ilLanguagesInstalledAndUpdatedObjective $objective, array $language_keys): void
    {
        (new ReflectionMethod($objective, 'installLanguages'))->invoke($objective, $language_keys);
    }

    /**
     * The central integration guarantee of installLanguages(): a single call
     * with a mixed list of already-installed and not-yet-installed language
     * keys must actually flush+reinstall each of them - the not-yet-installed
     * one via InstallLanguage, the already-installed one via UpdateLanguage -
     * not merely report them as belonging to the correct bucket. Both
     * Activities are built via their forSetup() factory here (the
     * constructor's default when no Activity is injected), sharing the same
     * mocked ilSetupLanguage.
     */
    public function testInstallLanguagesActuallyFlushesBothTheNewlyInstalledAndTheAlreadyInstalledLanguage(): void
    {
        $log = [];
        // 'de' is already installed, 'fr' is not.
        $setup_language = $this->createSetupLanguageMock(['de'], $log);

        $objective = new ilLanguagesInstalledAndUpdatedObjective($setup_language);
        $this->invokeInstallLanguages($objective, ['de', 'fr']);

        // 'fr' is flushed once by InstallLanguage (fresh install); 'de' is
        // flushed once by UpdateLanguage (refresh of an already installed
        // language) - in that order, since installLanguages() runs
        // InstallLanguage before UpdateLanguage.
        $this->assertSame(['fr', 'de'], $log);
    }

    /**
     * Regression guard for the documented "harmless double cycle": a
     * language that InstallLanguage has just installed is, within the very
     * same installLanguages() call, immediately flushed a second time by
     * UpdateLanguage - because by the time UpdateLanguage::perform() queries
     * getInstalledLanguages() again, the just-installed language is now
     * reported as installed too, exactly like a real database would behave
     * after InstallLanguage's write. This is intentional (see
     * ilLanguagesInstalledAndUpdatedObjective::installLanguages() docblock),
     * not wasted work accidentally introduced by the two-Activity split.
     */
    public function testFreshlyInstalledLanguageIsImmediatelyRefreshedAgainByUpdateLanguage(): void
    {
        $log = [];
        $newly_installed = [];

        $setup_language = $this->createStub(ilSetupLanguage::class);
        $setup_language->method('getInstalledLanguages')->willReturnCallback(
            static function () use (&$newly_installed): array {
                return array_merge(['de'], $newly_installed);
            }
        );
        $setup_language->method('getAvailableLanguagesForInstallation')->willReturn([]);
        $setup_language->method('getLocalLanguages')->willReturn([]);
        $setup_language->method('getInvalidLocalLanguageFiles')->willReturn([]);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $setup_language->method('flushLanguageForInstallation')->willReturnCallback(
            static function (string $lang_key) use (&$log): void {
                $log[] = $lang_key;
            }
        );
        // The DB write that makes 'fr' visible as installed to every
        // subsequent getInstalledLanguages() call, exactly like a real
        // INSERT into object_data would.
        $setup_language->method('registerInstalledLanguage')->willReturnCallback(
            static function (string $lang_key) use (&$newly_installed): void {
                $newly_installed[] = $lang_key;
            }
        );

        $objective = new ilLanguagesInstalledAndUpdatedObjective($setup_language);
        $this->invokeInstallLanguages($objective, ['de', 'fr']);

        // 'fr': flushed once by InstallLanguage (fresh install). Then
        // UpdateLanguage runs against the now-current installed list
        // (['de', 'fr']) and flushes both again - 'de' because it always
        // was installed, 'fr' because InstallLanguage just registered it.
        $this->assertSame(['fr', 'de', 'fr'], $log);
    }

    // ------------------------------------------------ achieve(): invalid languages

    /**
     * @param list<string> $flushed collects every flushed language key
     */
    private function createAchievableSetupLanguage(array $installed, array $invalid, array &$flushed, bool $default_directories = false): Stub&ilSetupLanguage
    {
        $setup_language = $this->createStub(ilSetupLanguage::class);
        $setup_language->method('getInstalledLanguages')->willReturn($installed);
        $setup_language->method('getAvailableLanguagesForInstallation')->willReturn([]);
        $setup_language->method('getLocalLanguages')->willReturn([]);
        $setup_language->method('getInvalidLocalLanguageFiles')->willReturn([]);
        $setup_language->method('checkLanguageForInstallation')->willReturnCallback(
            static fn(string $lang_key): bool => !in_array($lang_key, $invalid, true)
        );
        $setup_language->method('usesDefaultLanguageFileDirectories')->willReturn($default_directories);
        $setup_language->method('flushLanguageForInstallation')->willReturnCallback(
            static function (string $lang_key) use (&$flushed): void {
                $flushed[] = $lang_key;
            }
        );

        return $setup_language;
    }

    private function databaseStub(): ilDBInterface
    {
        return $this->createStub(ilDBInterface::class);
    }

    /**
     * An invalid language file only skips that language (reported), it does not abort the whole
     * Setup run - the valid languages are still refreshed.
     */
    public function testAchieveSkipsInvalidLanguagesAndInformsTheAdministrator(): void
    {
        $flushed = [];
        $setup_language = $this->createAchievableSetupLanguage(['de', 'xx', 'fr'], ['xx'], $flushed);
        $messages = [];
        $io = $this->createStub(Setup\AdminInteraction::class);
        $io->method('inform')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        (new ilLanguagesInstalledAndUpdatedObjective($setup_language))->achieve(new Setup\ArrayEnvironment([
            Setup\Environment::RESOURCE_DATABASE => $this->databaseStub(),
            Setup\Environment::RESOURCE_ADMIN_INTERACTION => $io,
        ]));

        $this->assertSame(['de', 'fr'], $flushed, 'valid languages are refreshed, the invalid one is untouched');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('xx', $messages[0]);
        $this->assertStringNotContainsString('de', str_replace('Skipped languages', '', $messages[0]));
    }

    public function testAchieveReportsSkippedLanguagesViaErrorLogWithoutAdminInteraction(): void
    {
        $this->expectErrorLog();
        $flushed = [];
        $setup_language = $this->createAchievableSetupLanguage(['de', 'xx'], ['xx'], $flushed);

        (new ilLanguagesInstalledAndUpdatedObjective($setup_language))->achieve(new Setup\ArrayEnvironment([
            Setup\Environment::RESOURCE_DATABASE => $this->databaseStub(),
        ]));

        $this->assertSame(['de'], $flushed);
        $this->assertStringContainsString('xx', (string) file_get_contents((string) ini_get('error_log')));
    }

    public function testAchieveWithOnlyInvalidLanguagesDoesNotThrowAndWritesNothing(): void
    {
        $flushed = [];
        $setup_language = $this->createAchievableSetupLanguage(['xx'], ['xx'], $flushed);
        $io = $this->createMock(Setup\AdminInteraction::class);
        $io->expects($this->once())->method('inform');

        (new ilLanguagesInstalledAndUpdatedObjective($setup_language))->achieve(new Setup\ArrayEnvironment([
            Setup\Environment::RESOURCE_DATABASE => $this->databaseStub(),
            Setup\Environment::RESOURCE_ADMIN_INTERACTION => $io,
        ]));

        $this->assertSame([], $flushed);
    }

    public function testAchieveDoesNotInformWhenEveryLanguageIsValid(): void
    {
        $flushed = [];
        $setup_language = $this->createAchievableSetupLanguage(['de'], [], $flushed);
        $io = $this->createMock(Setup\AdminInteraction::class);
        $io->expects($this->never())->method('inform');

        (new ilLanguagesInstalledAndUpdatedObjective($setup_language))->achieve(new Setup\ArrayEnvironment([
            Setup\Environment::RESOURCE_DATABASE => $this->databaseStub(),
            Setup\Environment::RESOURCE_ADMIN_INTERACTION => $io,
        ]));

        $this->assertSame(['de'], $flushed);
    }

    // ------------------------------------------ installLanguages(): directories

    /**
     * An instance with only the default directories (plugin Setup Objectives) must never refresh an
     * installed language - that would drop every component-contributed module from lng_data.
     */
    public function testAnInstanceWithDefaultDirectoriesInstallsMissingLanguagesButNeverRefreshes(): void
    {
        $flushed = [];
        $setup_language = $this->createAchievableSetupLanguage(['de'], [], $flushed, true);

        $this->invokeInstallLanguages(new ilLanguagesInstalledAndUpdatedObjective($setup_language), ['de', 'fr']);

        $this->assertSame(['fr'], $flushed);
    }

    public function testInstallLanguagesWithoutKeysDoesNothing(): void
    {
        $flushed = [];
        $setup_language = $this->createAchievableSetupLanguage(['de'], [], $flushed);

        $this->invokeInstallLanguages(new ilLanguagesInstalledAndUpdatedObjective($setup_language), []);

        $this->assertSame([], $flushed);
    }

    // ------------------------------------------------ client data directory

    private function iniStub(string $datadir): ilIniFile
    {
        $ini = $this->createStub(ilIniFile::class);
        $ini->method('readVariable')->willReturnCallback(
            static fn(string $group, string $name): string => $group === 'clients' && $name === 'datadir' ? $datadir : ''
        );
        return $ini;
    }

    /**
     * @param array<string, mixed> $resources
     * @return list<?string> every value setClientDataDir() was called with
     */
    private function achieveCapturingClientDataDir(array $resources): array
    {
        $flushed = [];
        $calls = [];
        $setup_language = $this->createAchievableSetupLanguage([], [], $flushed);
        $setup_language->method('setClientDataDir')->willReturnCallback(
            static function (?string $dir) use (&$calls): void {
                $calls[] = $dir;
            }
        );

        (new ilLanguagesInstalledAndUpdatedObjective($setup_language))->achieve(new Setup\ArrayEnvironment(
            [Setup\Environment::RESOURCE_DATABASE => $this->databaseStub()] + $resources
        ));

        return $calls;
    }

    public function testAchieveTakesTheClientDataDirFromTheSetupEnvironment(): void
    {
        $datadir = sys_get_temp_dir() . '/ilias_obj_cdd_' . bin2hex(random_bytes(4));
        mkdir($datadir . '/myclient', 0775, true);

        try {
            $calls = $this->achieveCapturingClientDataDir([
                Setup\Environment::RESOURCE_ILIAS_INI => $this->iniStub($datadir . '/'),
                Setup\Environment::RESOURCE_CLIENT_ID => 'myclient',
            ]);
        } finally {
            MigratedPoFixture::removeDirectory($datadir);
        }

        $this->assertSame([$datadir . '/myclient'], $calls);
    }

    /**
     * @param array<string, mixed> $resources
     */
    #[DataProvider('incompleteClientEnvironments')]
    public function testAchieveKeepsTheDefaultResolutionWithoutAUsableClientDataDir(\Closure $resources): void
    {
        $datadir = sys_get_temp_dir() . '/ilias_obj_cdd_' . bin2hex(random_bytes(4));
        mkdir($datadir . '/myclient', 0775, true);

        try {
            $calls = $this->achieveCapturingClientDataDir($resources($this->iniStub($datadir)));
        } finally {
            MigratedPoFixture::removeDirectory($datadir);
        }

        $this->assertSame([], $calls, 'setClientDataDir() must not be called (not even with null)');
    }

    public static function incompleteClientEnvironments(): array
    {
        return [
            'no ini, no client id' => [static fn(ilIniFile $ini): array => []],
            'no client id' => [static fn(ilIniFile $ini): array => [Setup\Environment::RESOURCE_ILIAS_INI => $ini]],
            'no ini' => [static fn(ilIniFile $ini): array => [Setup\Environment::RESOURCE_CLIENT_ID => 'myclient']],
            'client directory does not exist' => [static fn(ilIniFile $ini): array => [
                Setup\Environment::RESOURCE_ILIAS_INI => $ini,
                Setup\Environment::RESOURCE_CLIENT_ID => 'other',
            ]],
            'unsafe client id' => [static fn(ilIniFile $ini): array => [
                Setup\Environment::RESOURCE_ILIAS_INI => $ini,
                Setup\Environment::RESOURCE_CLIENT_ID => '../myclient',
            ]],
        ];
    }

    // ------------------------------------------------------------ getHash()

    private static function directory(string $prefix, string $path, bool $local = false): LanguageFileDirectory
    {
        return MigratedPoFixture::directory($prefix, $path, $local);
    }

    private static function manager(LanguageFileDirectory ...$directories): LanguageFileDirectoryManager
    {
        return new LanguageFileDirectoryManager(
            new CustomizingLanguageFileDirectory(),
            new MainLanguageFileDirectory(),
            ...$directories
        );
    }

    private function hashFor(?LanguageFileDirectoryManager $manager): string
    {
        $setup_language = new ilSetupLanguage('en', $manager);
        return (new ilLanguagesInstalledAndUpdatedObjective(
            $setup_language,
            $this->createStub(\ILIAS\Language\Activities\InstallLanguage::class),
            $this->createStub(\ILIAS\Language\Activities\UpdateLanguage::class)
        ))->getHash();
    }

    public function testTheHashIsStableForTheSameDirectoryConfiguration(): void
    {
        $build = static fn(): LanguageFileDirectoryManager => new LanguageFileDirectoryManager(
            new CustomizingLanguageFileDirectory(),
            new MainLanguageFileDirectory(),
            self::directory('tos', 'components/ILIAS/TermsOfService/lang/')
        );

        $this->assertSame($this->hashFor($build()), $this->hashFor($build()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->hashFor($build()));
    }

    public function testTheDefaultDirectoriesHashLikeAnExplicitlyEqualConfiguration(): void
    {
        $this->assertSame(
            $this->hashFor(null),
            $this->hashFor(new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()))
        );
    }

    /**
     * Each part of a directory's configuration (class, prefix, path, suffix) and the presence of a
     * contributed directory must change the hash - otherwise the Setup keeps only one of two
     * differently configured objectives.
     */
    #[DataProvider('differingConfigurations')]
    public function testTheHashDiffersForDifferentDirectoryConfigurations(\Closure $a, \Closure $b): void
    {
        $this->assertNotSame($this->hashFor($a()), $this->hashFor($b()));
    }

    public static function differingConfigurations(): array
    {
        return [
            'default vs. with contributed directory' => [
                static fn() => null,
                static fn() => self::manager(self::directory('tos', 'components/ILIAS/TermsOfService/lang/')),
            ],
            'prefix differs' => [
                static fn() => self::manager(self::directory('tos', 'components/x/lang/')),
                static fn() => self::manager(self::directory('file', 'components/x/lang/')),
            ],
            'path differs' => [
                static fn() => self::manager(self::directory('tos', 'components/x/lang/')),
                static fn() => self::manager(self::directory('tos', 'components/y/lang/')),
            ],
            'suffix differs' => [
                static fn() => self::manager(self::directory('tos', 'components/x/lang/')),
                static fn() => self::manager(self::directory('tos', 'components/x/lang/', true)),
            ],
            'only the class differs' => [
                static fn() => self::manager(new \ILIAS\Language\ComponentTranslation\ComponentLanguageFileDirectory(new \ILIAS\Language(), 'tos')),
                static function (): LanguageFileDirectoryManager {
                    $component = new \ILIAS\Language\ComponentTranslation\ComponentLanguageFileDirectory(new \ILIAS\Language(), 'tos');
                    return self::manager(self::directory('tos', $component->getPath()));
                },
            ],
        ];
    }
}
