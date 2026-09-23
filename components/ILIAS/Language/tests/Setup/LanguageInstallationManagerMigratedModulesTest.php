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

use ILIAS\Language\Activities\SafeToDisplayActivityError;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\Gettext\Catalog;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\LocalChangeComments;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Language\Setup\LanguageDataNotSavedException;
use ILIAS\Language\Setup\LanguageInstallationManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LanguageInstallationManager for modules migrated to PO/MO ("pilot" below): the shipped `.po` is the
 * only source of shipped values, the three-way reconciliation between local value (L), previously
 * shipped value (O = overlay "original") and newly shipped value (S), and everything that happens
 * after the database write (collation check, cache invalidation, overlay sync).
 *
 * The database is a recording mock: quote() hands out placeholder tokens and remembers the real
 * values, so the written lng_data rows (including a NULL local_change) and lng_modules arrays can be
 * asserted as data instead of as SQL strings.
 */
class LanguageInstallationManagerMigratedModulesTest extends TestCase
{
    private const string NOW = '2026-01-02 03:04:05';
    private const string MODULE = 'pilot';

    private string $root;
    private LanguageFileDirectory $directory;

    /** @var list<mixed> */
    private array $quoted = [];
    /** @var list<string> */
    private array $queries = [];
    /** @var list<array<string, string>>|null rows the collation check reads, null = what was written */
    private ?array $collation_rows = null;
    /** @var list<array<string, string>> */
    private array $pending_rows = [];

    /** @var array<string, array<string, string>> */
    private array $db_local_changes = [];
    /** @var array<string, array<string, string>> */
    private array $db_entries = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ilias_lim_test_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/lang/customizing', 0775, true);
        mkdir($this->root . '/client-data', 0775, true);
        $this->directory = MigratedPoFixture::directory(self::MODULE, 'components/pilot/lang/');
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->root);
    }

    // ------------------------------------------------------------ fixtures

    private function db(): ilDBInterface
    {
        $statement = $this->createStub(ilDBStatement::class);
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(function (mixed $value, string $type): string {
            $this->quoted[] = $value;
            return 'Q' . (count($this->quoted) - 1) . 'Q';
        });
        $db->method('in')->willReturnCallback(
            static fn(string $field, array $values): string => $field . " IN ('" . implode("','", $values) . "')"
        );
        $db->method('manipulate')->willReturnCallback(function (string $query): int {
            $this->queries[] = $query;
            return 1;
        });
        $db->method('query')->willReturnCallback(function (string $query) use ($statement): ilDBStatement {
            $this->queries[] = $query;
            $this->pending_rows = $this->collation_rows ?? array_map(
                static fn(string $module, array $lang_array): array => ['module' => $module, 'lang_array' => serialize($lang_array)],
                array_keys($this->lngModules()),
                $this->lngModules()
            );
            return $statement;
        });
        $db->method('fetchAssoc')->willReturnCallback(fn(): ?array => array_shift($this->pending_rows));
        $db->method('now')->willReturn('NOW()');
        $db->method('nextId')->willReturn(1);

        return $db;
    }

    private function repository(): InstalledLanguageRepository
    {
        $repository = $this->createStub(InstalledLanguageRepository::class);
        $repository->method('getLocalChanges')->willReturnCallback(
            fn(string $lang_key, string $min_date = ''): array => $min_date === '' ? $this->db_local_changes : []
        );
        $repository->method('getLanguageEntries')->willReturnCallback(fn(): array => $this->db_entries);

        return $repository;
    }

    private function manager(
        ?\Closure $client_data_dir_resolver = null,
        ?\Closure $language_cache_invalidator = null,
        bool $with_resolver = true
    ): LanguageInstallationManager {
        $client_data_dir = $this->root . '/client-data';
        return new LanguageInstallationManager(
            $this->db(),
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                new MainLanguageFileDirectory(),
                $this->directory
            ),
            $this->root,
            $this->repository(),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')),
            $with_resolver ? ($client_data_dir_resolver ?? static fn(): string => $client_data_dir) : null,
            $language_cache_invalidator
        );
    }

    /**
     * @param array<string, string|array<string, mixed>> $entries
     */
    private function shipPo(array $entries, string $lang_key = 'de'): void
    {
        MigratedPoFixture::writePo(
            $this->root . '/components/pilot/lang/pilot_' . $lang_key . '.po',
            MigratedPoFixture::catalog(self::MODULE, $entries)
        );
    }

    /**
     * @param array<string, string|array<string, mixed>> $entries
     */
    private function seedOverlay(array $entries): void
    {
        MigratedPoFixture::writePair($this->overlayBase(), MigratedPoFixture::catalog(self::MODULE, $entries));
    }

    private function overlayBase(): string
    {
        return $this->root . '/client-data/lang/components/pilot/lang/pilot_de';
    }

    private function overlayPo(): Catalog
    {
        return MigratedPoFixture::readPo($this->overlayBase() . '.po');
    }

    /**
     * @param list<string> $lines raw lines after the header marker
     */
    private function writeLang(string $relative_file, array $lines): void
    {
        $file = $this->root . '/' . $relative_file;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, "header\n<!-- language file start -->\n" . implode("\n", $lines) . "\n");
    }

    private function resolveToken(string $token): mixed
    {
        return $this->quoted[(int) trim($token, 'Q')];
    }

    /**
     * @return array<string, array{value: string, local_change: ?string}> "module|identifier" => row
     *         (last write wins, like ON DUPLICATE KEY UPDATE)
     */
    private function lngData(): array
    {
        $rows = [];
        foreach ($this->queries as $query) {
            if (!str_starts_with($query, 'INSERT INTO lng_data')) {
                continue;
            }
            preg_match_all('/\((Q\d+Q),(Q\d+Q),(Q\d+Q),(Q\d+Q),(Q\d+Q),(Q\d+Q)\)/', $query, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $key = $this->resolveToken($match[1]) . '|' . $this->resolveToken($match[2]);
                $rows[$key] = ['value' => $this->resolveToken($match[4]), 'local_change' => $this->resolveToken($match[5])];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, string>> module => identifier => value
     */
    private function lngModules(): array
    {
        $modules = [];
        foreach ($this->queries as $query) {
            if (!str_starts_with($query, 'INSERT INTO lng_modules')) {
                continue;
            }
            preg_match_all('/\((Q\d+Q),(Q\d+Q),(Q\d+Q)\)/', $query, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $modules[$this->resolveToken($match[1])] = unserialize($this->resolveToken($match[3]));
            }
        }

        return $modules;
    }

    /**
     * What error_log() wrote during this test so far - PHPUnit redirects error_log to a capture file
     * per test (call expectErrorLog() in tests that expect logging, so it is not echoed).
     */
    private function errorLog(): string
    {
        $file = (string) ini_get('error_log');
        return $file !== '' && is_file($file) ? (string) file_get_contents($file) : '';
    }

    // ------------------------------------------------- source of the values

    /**
     * The tos lines could be removed from lang/ilias_xx.lang: the database is filled from the
     * shipped `.po` alone.
     */
    public function testValuesOfAMigratedModuleComeFromThePoEvenWithoutAnyLangLine(): void
    {
        $this->shipPo(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->writeLang('lang/ilias_de.lang', ['common#:#yes#:#Ja']);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(
            [
                'common|yes' => ['value' => 'Ja', 'local_change' => null],
                'pilot|greeting' => ['value' => 'Hallo', 'local_change' => null],
                'pilot|farewell' => ['value' => 'Tschüss', 'local_change' => null],
            ],
            $this->lngData()
        );
        $this->assertSame(['greeting' => 'Hallo', 'farewell' => 'Tschüss'], $this->lngModules()[self::MODULE]);
    }

    public function testLangLinesOfAMigratedModuleInNonLocalFilesAreIgnored(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $this->writeLang('lang/ilias_de.lang', ['pilot#:#greeting#:#Alt aus lang/', 'pilot#:#stale#:#Veraltet']);
        $this->writeLang('components/pilot/lang/ilias_de.lang', ['greeting#:#Alt aus Komponente']);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['greeting' => 'Hallo'], $this->lngModules()[self::MODULE]);
        $this->assertArrayNotHasKey('pilot|stale', $this->lngData());
    }

    /**
     * Only languages that ship a `.po` are migrated: for another language the `.lang` lines are used.
     */
    public function testAModuleIsOnlyMigratedForLanguagesWithAShippedPo(): void
    {
        $this->shipPo(['greeting' => 'Hallo'], 'de');
        $this->writeLang('components/pilot/lang/ilias_en.lang', ['greeting#:#Hello']);

        $this->manager()->insertLanguageForInstallation('en');

        $this->assertSame(['greeting' => 'Hello'], $this->lngModules()[self::MODULE]);
        $this->assertDirectoryDoesNotExist($this->root . '/client-data/lang');
    }

    public function testInstallCreatesTheOverlayWithShippedOriginals(): void
    {
        $this->shipPo(['greeting' => ['value' => 'Hello', 'fuzzy' => true]]);

        $this->manager()->insertLanguageForInstallation('de');

        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Hello', $greeting->getTranslation());
        $this->assertSame('Hello', LocalChangeComments::getOriginal($greeting));
        $this->assertNull(LocalChangeComments::getLocalChange($greeting));
        $this->assertTrue($greeting->hasFlag('fuzzy'));
        $this->assertSame(['greeting' => 'Hello'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
    }

    // --------------------------------------------------- three-way decision

    /**
     * @param ?string $local L from the database seed (null = no local change)
     * @param ?string $original O, the overlay's "original" (null = no overlay entry)
     */
    #[DataProvider('threeWayCases')]
    public function testThreeWayReconciliationOfADatabaseLocalChange(
        ?string $local,
        ?string $original,
        string $shipped,
        string $expected_value,
        bool $expected_row_written_as_shipped
    ): void {
        $this->shipPo(['greeting' => $shipped]);
        if ($original !== null) {
            $this->seedOverlay(['greeting' => [
                'value' => $local ?? $original,
                'original' => $original,
                'local_change' => $local !== null && $local !== $original ? '2025-05-05T05:05:05Z' : null,
            ]]);
        }
        if ($local !== null) {
            $this->db_local_changes = [self::MODULE => ['greeting' => $local]];
        }

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame($expected_value, $this->lngModules()[self::MODULE]['greeting']);
        if ($expected_row_written_as_shipped) {
            $this->assertSame(['value' => $shipped, 'local_change' => null], $this->lngData()['pilot|greeting']);
        } else {
            $this->assertArrayNotHasKey('pilot|greeting', $this->lngData(), 'the local lng_data row is kept as it is');
        }
        $overlay_entry = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame($expected_value, $overlay_entry->getTranslation());
        $this->assertSame($shipped, LocalChangeComments::getOriginal($overlay_entry), 'original always moves to S');
        $this->assertSame($expected_value !== $shipped, LocalChangeComments::getLocalChange($overlay_entry) !== null);
    }

    public static function threeWayCases(): array
    {
        return [
            'no L -> S' => [null, 'Alt', 'Neu', 'Neu', true],
            'no L, no overlay -> S' => [null, null, 'Neu', 'Neu', true],
            'L === S -> S, no longer local' => ['Neu', 'Alt', 'Neu', 'Neu', true],
            'L === O, only S changed -> S' => ['Alt', 'Alt', 'Neu', 'Neu', true],
            'L differs from O and S -> L wins' => ['Eigen', 'Alt', 'Neu', 'Eigen', false],
            'L differs, S unchanged -> L wins' => ['Eigen', 'Alt', 'Alt', 'Eigen', false],
            'L without overlay -> L wins' => ['Eigen', null, 'Neu', 'Eigen', false],
        ];
    }

    /**
     * A value from the customizing file always stays local - even when it equals the previously
     * shipped value (which for a database local change would let S win).
     */
    public function testAValueFromTheCustomizingFileAlwaysStaysLocal(): void
    {
        $this->shipPo(['greeting' => 'Neu']);
        $this->seedOverlay(['greeting' => ['value' => 'Alt', 'original' => 'Alt']]);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['pilot#:#greeting#:#Alt']);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['value' => 'Alt', 'local_change' => self::NOW], $this->lngData()['pilot|greeting']);
        $this->assertSame('Alt', $this->lngModules()[self::MODULE]['greeting']);
        $this->assertNotNull(LocalChangeComments::getLocalChange($this->overlayPo()->find(self::MODULE, 'greeting')));
    }

    /**
     * A local change that exists only in the overlay (e.g. lng_data was lost or rebuilt) is taken
     * over on install/update - with its overlay timestamp, as a local lng_data row.
     */
    public function testAnOverlayOnlyLocalChangeIsTakenOverOnInstall(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => '2025-05-05T05:05:05Z'],
            'added_locally' => ['value' => 'Eigene Variable', 'local_change' => '2025-06-06T06:06:06Z'],
            'orphan' => ['value' => 'Weder geshippt noch lokal', 'original' => 'Weder geshippt noch lokal'],
        ]);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['value' => 'Servus', 'local_change' => '2025-05-05 05:05:05'], $this->lngData()['pilot|greeting']);
        $this->assertSame(
            ['value' => 'Eigene Variable', 'local_change' => '2025-06-06 06:06:06'],
            $this->lngData()['pilot|added_locally']
        );
        $this->assertSame(
            ['greeting' => 'Servus', 'added_locally' => 'Eigene Variable'],
            $this->lngModules()[self::MODULE]
        );
        $this->assertNull($this->overlayPo()->find(self::MODULE, 'orphan'), 'neither shipped nor local: dropped');
        $this->assertSame(
            '2025-05-05T05:05:05Z',
            LocalChangeComments::getLocalChange($this->overlayPo()->find(self::MODULE, 'greeting')),
            'the original timestamp of the local change survives'
        );
    }

    /**
     * Boundary: an overlay local change whose timestamp cannot be parsed is still a local change -
     * it is recorded with the current time instead of being lost or written with a bogus date.
     */
    public function testAnOverlayOnlyLocalChangeWithAnUnparsableTimestampIsRecordedWithTheCurrentTime(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => 'yesterday'],
        ]);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['value' => 'Servus', 'local_change' => self::NOW], $this->lngData()['pilot|greeting']);
    }

    public function testALocalEntryWithoutShippedCounterpartIsKept(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $this->db_local_changes = [self::MODULE => ['custom' => 'Eigene Variable']];

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['custom' => 'Eigene Variable', 'greeting' => 'Hallo'], $this->lngModules()[self::MODULE]);
        $custom = $this->overlayPo()->find(self::MODULE, 'custom');
        $this->assertNull(LocalChangeComments::getOriginal($custom));
        $this->assertNotNull(LocalChangeComments::getLocalChange($custom));
    }

    // ----------------------------------------------- remove / apply local

    public function testRemovingLocalChangesRebuildsTheOverlayFromTheShippedPo(): void
    {
        $this->shipPo(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => '2025-05-05T05:05:05Z'],
            'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
            'added_locally' => ['value' => 'Eigene Variable', 'local_change' => '2025-06-06T06:06:06Z'],
        ]);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['pilot#:#greeting#:#Aus Customizing']);

        $this->manager()->insertLanguageForRemovingLocalChanges('de');

        $this->assertSame(
            [
                'pilot|greeting' => ['value' => 'Hallo', 'local_change' => null],
                'pilot|farewell' => ['value' => 'Tschüss', 'local_change' => null],
            ],
            $this->lngData()
        );
        $this->assertSame(['greeting' => 'Hallo', 'farewell' => 'Tschüss'], $this->lngModules()[self::MODULE]);
        $overlay = $this->overlayPo();
        $this->assertSame(['farewell', 'greeting'], array_map(static fn($e): string => $e->getId(), $overlay->getEntries()));
        $this->assertNull(LocalChangeComments::getLocalChange($overlay->find(self::MODULE, 'greeting')));
        $this->assertSame(
            ['farewell' => 'Tschüss', 'greeting' => 'Hallo'],
            MigratedPoFixture::readMo($this->overlayBase() . '.mo')
        );
    }

    /**
     * Applying local changes does not merge the shipped `.po` again: the seed (all stored entries) is
     * the base, the customizing file goes on top as local change and the overlay follows.
     */
    public function testApplyingLocalChangesPutsTheCustomizingValueOnTopOfTheStoredEntries(): void
    {
        $this->shipPo(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
        ]);
        $this->db_entries = [self::MODULE => ['greeting' => 'Hallo', 'farewell' => 'Tschüss'], 'common' => ['yes' => 'Ja']];
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['pilot#:#greeting#:#Grüß Gott']);

        $this->manager()->insertLanguageForApplyingLocalChanges('de');

        $this->assertSame(['pilot|greeting' => ['value' => 'Grüß Gott', 'local_change' => self::NOW]], $this->lngData());
        $this->assertSame(
            [self::MODULE => ['greeting' => 'Grüß Gott', 'farewell' => 'Tschüss'], 'common' => ['yes' => 'Ja']],
            $this->lngModules()
        );
        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Grüß Gott', $greeting->getTranslation());
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($greeting));
        $this->assertNotNull(LocalChangeComments::getLocalChange($greeting));
    }

    // ------------------------------------------------- after the DB write

    public function testAModuleArrayThatCannotBeReadBackThrowsLanguageDataNotSaved(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $this->collation_rows = [
            ['module' => 'common', 'lang_array' => serialize(['yes' => 'Ja'])],
            ['module' => self::MODULE, 'lang_array' => 'a:1:{s:8:"greeting";s:5:"Hal'],
        ];
        $invalidated = [];
        $cwd = getcwd();

        try {
            $this->manager(null, static function (string $lang_key) use (&$invalidated): void {
                $invalidated[] = $lang_key;
            })->insertLanguageForInstallation('de');
            $this->fail('Expected a LanguageDataNotSavedException');
        } catch (LanguageDataNotSavedException $e) {
            $this->assertInstanceOf(SafeToDisplayActivityError::class, $e);
            $this->assertStringContainsString("'pilot'", $e->getMessage());
            $this->assertStringContainsString("'de'", $e->getMessage());
        }

        $this->assertSame([], $invalidated, 'no cache invalidation for data that was not saved correctly');
        $this->assertFileDoesNotExist($this->overlayBase() . '.po', 'no overlay for data that was not saved correctly');
        $this->assertSame($cwd, getcwd(), 'the working directory is restored on the exception path');
        $collation_query = array_values(array_filter($this->queries, static fn(string $q): bool => str_starts_with($q, 'SELECT')));
        $this->assertCount(1, $collation_query);
        $this->assertStringContainsString("module IN ('pilot')", $collation_query[0]);
    }

    public function testASerializedFalseIsAlsoDetectedAsNotSaved(): void
    {
        $this->writeLang('lang/ilias_de.lang', ['common#:#yes#:#Ja']);
        $this->collation_rows = [['module' => 'common', 'lang_array' => serialize(false)]];

        $this->expectException(LanguageDataNotSavedException::class);
        $this->manager()->insertLanguageForInstallation('de');
    }

    public function testTheLanguageCacheIsInvalidatedWithTheLanguageKeyBeforeTheOverlayIsSynced(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $overlay_po = $this->overlayBase() . '.po';
        $calls = [];

        $this->manager(null, function (string $lang_key) use (&$calls, $overlay_po): void {
            $calls[] = [$lang_key, count(array_filter($this->queries, static fn(string $q): bool => str_starts_with($q, 'INSERT INTO lng_modules'))), is_file($overlay_po)];
        })->insertLanguageForInstallation('de');

        $this->assertSame([['de', 1, false]], $calls, 'once, after the lng_modules write, before the overlay sync');
    }

    public function testAFailingCacheInvalidatorIsLoggedAndSwallowed(): void
    {
        $this->expectErrorLog();
        $this->shipPo(['greeting' => 'Hallo']);

        $this->manager(null, static function (): void {
            throw new RuntimeException('cache is down');
        })->insertLanguageForInstallation('de');

        $this->assertSame(['greeting' => 'Hallo'], $this->lngModules()[self::MODULE]);
        $this->assertFileExists($this->overlayBase() . '.mo', 'the overlay sync still runs');
        $this->assertStringContainsString('cache is down', $this->errorLog());
    }

    /**
     * Root-proof overlay failure (a file where the overlay's "lang" directory must be created): logged,
     * the database write stays.
     */
    public function testAnOverlaySyncFailureIsLoggedAndTheDatabaseWriteStays(): void
    {
        $this->expectErrorLog();
        $this->shipPo(['greeting' => 'Hallo']);
        rmdir($this->root . '/client-data');
        mkdir($this->root . '/client-data');
        file_put_contents($this->root . '/client-data/lang', 'not a directory');

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->manager()->insertLanguageForInstallation('de');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['greeting' => 'Hallo'], $this->lngModules()[self::MODULE]);
        $this->assertStringContainsString('Could not sync migrated language file for module "pilot"', $this->errorLog());
    }

    /**
     * A corrupt overlay `.po` must not block an update: it is ignored for the reconciliation (logged)
     * and rebuilt afterwards.
     */
    public function testACorruptOverlayIsIgnoredAndRebuiltOnUpdate(): void
    {
        $this->expectErrorLog();
        $this->shipPo(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);
        file_put_contents($this->overlayBase() . '.po', "msgid \"kaputt\n");
        $this->db_local_changes = [self::MODULE => ['farewell' => 'Pfiat di']];

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['farewell' => 'Pfiat di', 'greeting' => 'Hallo'], $this->lngModules()[self::MODULE]);
        $overlay = $this->overlayPo();
        $this->assertSame('Pfiat di', $overlay->find(self::MODULE, 'farewell')->getTranslation());
        $this->assertSame('Tschüss', LocalChangeComments::getOriginal($overlay->find(self::MODULE, 'farewell')));
        $this->assertSame(
            ['farewell' => 'Pfiat di', 'greeting' => 'Hallo'],
            MigratedPoFixture::readMo($this->overlayBase() . '.mo')
        );
        $this->assertStringContainsString('Could not read the overlay of migrated module "pilot"', $this->errorLog());
    }

    public function testTheResolversClientDataDirIsUsedAndResolvedAtCallTime(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere);
        $target = null;
        $manager = $this->manager(static function () use (&$target): ?string {
            return $target;
        });

        $target = $elsewhere;
        $manager->insertLanguageForInstallation('de');

        $this->assertFileExists($elsewhere . '/lang/components/pilot/lang/pilot_de.mo');
        $this->assertDirectoryDoesNotExist($this->root . '/client-data/lang');
    }

    #[DataProvider('noClientDataDir')]
    public function testWithoutClientDataDirTheDatabaseIsWrittenButNoOverlay(bool $with_resolver): void
    {
        $this->shipPo(['greeting' => 'Hallo']);

        $this->manager(static fn(): ?string => null, null, $with_resolver)->insertLanguageForInstallation('de');

        $this->assertSame(['greeting' => 'Hallo'], $this->lngModules()[self::MODULE]);
        $this->assertDirectoryDoesNotExist($this->root . '/client-data/lang');
    }

    public static function noClientDataDir(): array
    {
        return ['resolver returns null' => [true], 'no resolver' => [false]];
    }

    // ------------------------------------------------------------ duplicates

    /**
     * Shipped ilias_fa.lang/ilias_nl.lang contain duplicates: the first occurrence wins, the update
     * does not abort, and the duplicates are reported.
     */
    public function testADuplicateInOneFileKeepsTheFirstOccurrenceAndIsReported(): void
    {
        $this->expectErrorLog();
        $this->writeLang('lang/ilias_de.lang', [
            'common#:#yes#:#Ja',
            'common#:#yes#:#Doppelt',
            'common#:#no#:#Nein',
        ]);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['yes' => 'Ja', 'no' => 'Nein'], $this->lngModules()['common']);
        $this->assertSame(['value' => 'Ja', 'local_change' => null], $this->lngData()['common|yes']);
        $this->assertStringContainsString('Duplicate language entries for language "de"', $this->errorLog());
        $this->assertStringContainsString('common#:#yes', $this->errorLog());
    }

    public function testTheSameEntryInTwoDifferentFilesIsNotADuplicate(): void
    {
        $this->writeLang('lang/ilias_de.lang', ['common#:#yes#:#Ja']);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['common#:#yes#:#Jawohl']);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['yes' => 'Jawohl'], $this->lngModules()['common']);
        $this->assertStringNotContainsString('Duplicate', $this->errorLog());
    }
}
