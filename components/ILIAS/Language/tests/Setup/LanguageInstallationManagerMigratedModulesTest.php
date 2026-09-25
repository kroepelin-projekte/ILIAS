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
use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\LocalChangeComments;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
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

    private function overlayPo(): TranslationCatalog
    {
        return MigratedPoFixture::readPo($this->overlayBase() . '.po');
    }

    /**
     * Shipped `.po` plus overlay delta, as served (MigratedLanguageFileSync::loadModuleTranslations()) -
     * the overlay itself only holds the entries that differ from the shipped `.po`.
     *
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>
     */
    private function effective(): array
    {
        return MigratedLanguageFileSync::loadModuleTranslations(
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $this->directory),
            'de',
            self::MODULE,
            $this->root . '/client-data',
            $this->root
        ) ?? [];
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
            if (str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')) {
                preg_match_all('/\((Q\d+Q),(Q\d+Q),(Q\d+Q),(Q\d+Q),(Q\d+Q),(Q\d+Q)\)/', $query, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $key = $this->resolveToken($match[1]) . '|' . $this->resolveToken($match[2]);
                    $rows[$key] = ['value' => $this->resolveToken($match[4]), 'local_change' => $this->resolveToken($match[5])];
                }
            } elseif (str_starts_with($query, /** @lang text */ 'DELETE FROM lng_data')) {
                $rows = $this->applyLngDataDelete($query, $rows);
            }
        }

        return $rows;
    }

    /**
     * Applies one recorded "DELETE FROM lng_data" query to $rows, modeling the two shapes
     * LanguageInstallationManager actually issues:
     * - "... WHERE lang_key = ? AND local_change IS NULL AND module IN (...)" (the bulk delete of
     *   every unchanged row of a migrated module before it is fully rewritten by the INSERT that
     *   follows - T1/F5's delete_unchanged_migrated_rows flag);
     * - "... WHERE lang_key = ? AND module = ? AND identifier IN (...)" (the per-module delete of
     *   identifiers resolveMigratedModule() dropped - T1/F5's $delete_row()).
     * Both use $ilDB->in(), whose stub (see db()) embeds the raw values literally rather than as
     * quote() tokens.
     *
     * @param array<string, array{value: string, local_change: ?string}> $rows
     * @return array<string, array{value: string, local_change: ?string}>
     */
    private function applyLngDataDelete(string $query, array $rows): array
    {
        if (preg_match(
            '/^DELETE FROM lng_data WHERE lang_key = Q\d+Q AND local_change IS NULL AND module IN \(\'(.*)\'\)$/',
            $query,
            $matches
        ) === 1) {
            $modules = explode("','", $matches[1]);
            foreach ($rows as $key => $row) {
                [$module] = explode('|', $key, 2);
                if (in_array($module, $modules, true) && $row['local_change'] === null) {
                    unset($rows[$key]);
                }
            }
            return $rows;
        }

        if (preg_match(
            '/^DELETE FROM lng_data WHERE lang_key = Q\d+Q AND module = (Q\d+Q) AND identifier IN \(\'(.*)\'\)$/',
            $query,
            $matches
        ) === 1) {
            $module = $this->resolveToken($matches[1]);
            foreach (explode("','", $matches[2]) as $identifier) {
                unset($rows[$module . '|' . $identifier]);
            }
            return $rows;
        }

        return $rows;
    }

    /**
     * The identifiers of $module dropped via the per-module "DELETE FROM lng_data ... AND module = ?
     * AND identifier IN (...)" query (see applyLngDataDelete()) - i.e. what resolveMigratedModule()'s
     * $delete_row callback actually removed, as opposed to a key that is merely absent from the
     * current run's output because it was never touched.
     *
     * @return list<string>
     */
    private function droppedIdentifiers(string $module): array
    {
        $dropped = [];
        foreach ($this->queries as $query) {
            if (
                preg_match(
                    '/^DELETE FROM lng_data WHERE lang_key = Q\d+Q AND module = (Q\d+Q) AND identifier IN \(\'(.*)\'\)$/',
                    $query,
                    $matches
                ) === 1
                && $this->resolveToken($matches[1]) === $module
            ) {
                $dropped = array_merge($dropped, explode("','", $matches[2]));
            }
        }

        return $dropped;
    }

    /**
     * @return array<string, array<string, string>> module => identifier => value
     */
    private function lngModules(): array
    {
        $modules = [];
        foreach ($this->queries as $query) {
            if (!str_starts_with($query, /** @lang text */ 'INSERT INTO lng_modules')) {
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

    /**
     * Adapted to the delta overlay (was: "install creates the overlay with shipped originals"): an
     * installation without local changes writes no overlay file - the shipped state is served as is.
     */
    public function testInstallWithoutLocalChangesWritesNoOverlay(): void
    {
        $this->shipPo(['greeting' => ['value' => 'Hello', 'fuzzy' => true]]);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertDirectoryDoesNotExist($this->root . '/client-data/lang');
        $this->assertSame(
            ['greeting' => ['value' => 'Hello', 'local_change' => false, 'local_change_date' => null, 'original' => 'Hello']],
            $this->effective()
        );
    }

    // -------------------------------------------------- concurrent overlay writes during the run

    /**
     * A migrated module that already has an overlay when insertLanguage() decides what to lock is
     * locked (MigratedLanguageFileSync::withOverlayLock()) for the WHOLE run, not merely for its own
     * final sync() call - so a concurrent writer (another admin GUI edit, or another install for the
     * same language) cannot interleave with the read-modify-write in between. Proven here by observing
     * MigratedLanguageFileSync's own lock bookkeeping (the private static $held_locks - the only
     * externally observable trace of "the lock is currently held") from inside the injected language
     * cache invalidator, which the production code calls partway through the run (after the database
     * write, before syncMigratedModules()) - i.e. strictly inside the locked section.
     */
    public function testAModuleWithAnExistingOverlayIsLockedForTheWholeRunNotJustItsOwnSync(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $this->seedOverlay(['greeting' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z']]);
        $lock_file = $this->overlayBase() . '.lock';
        $held_locks = new ReflectionProperty(MigratedLanguageFileSync::class, 'held_locks');

        $observed = null;
        $manager = $this->manager(null, function () use (&$observed, $held_locks, $lock_file): void {
            $observed = array_key_exists($lock_file, $held_locks->getValue());
        });

        $manager->insertLanguageForInstallation('de');

        $this->assertTrue($observed, 'the module\'s overlay lock must already be held at this point in the run');
        // and released again once the whole run is done - no lock left dangling
        $this->assertSame([], $held_locks->getValue());
    }

    /**
     * Deadlock protection: two modules with an overlay must always be locked in the same global order
     * (module name, byte-wise, see insertLanguage()'s own comment) - regardless of the order
     * getDirectories() happens to hand them out in, which differs between entry points (Setup vs. the
     * admin GUI). Proven here by contributing the two directories in REVERSE alphabetical order
     * ("zmod" before "amod") and observing the ORDER locks ended up held in via the private static
     * $held_locks (a PHP array preserves insertion order) - from inside the injected language cache
     * invalidator, called strictly after every lock of this run was already acquired (see the test
     * above).
     */
    public function testLocksAreAcquiredInSortedOrderRegardlessOfTheContributedDirectoryOrder(): void
    {
        $first_module = MigratedPoFixture::directory('zmod', 'components/pilot/lang/');
        $second_module = MigratedPoFixture::directory('amod', 'components/pilot/lang/');
        MigratedPoFixture::writePo(
            $this->root . '/components/pilot/lang/zmod_de.po',
            MigratedPoFixture::catalog('zmod', ['greeting' => 'Hallo'])
        );
        MigratedPoFixture::writePo(
            $this->root . '/components/pilot/lang/amod_de.po',
            MigratedPoFixture::catalog('amod', ['greeting' => 'Hallo'])
        );
        MigratedPoFixture::writePair(
            $this->root . '/client-data/lang/components/pilot/lang/zmod_de',
            MigratedPoFixture::catalog('zmod', ['greeting' => ['value' => 'Servus', 'original' => 'Hallo']])
        );
        MigratedPoFixture::writePair(
            $this->root . '/client-data/lang/components/pilot/lang/amod_de',
            MigratedPoFixture::catalog('amod', ['greeting' => ['value' => 'Servus', 'original' => 'Hallo']])
        );
        $held_locks = new ReflectionProperty(MigratedLanguageFileSync::class, 'held_locks');
        $observed_order = null;

        $manager = new LanguageInstallationManager(
            $this->db(),
            // "zmod" contributed BEFORE "amod" - the opposite of sorted order
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $first_module, $second_module),
            $this->root,
            $this->repository(),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')),
            fn(): string => $this->root . '/client-data',
            function () use (&$observed_order, $held_locks): void {
                $observed_order = array_map(
                    static fn(string $lock_file): string => basename($lock_file),
                    array_keys($held_locks->getValue())
                );
            }
        );

        $manager->insertLanguageForInstallation('de');

        $this->assertSame(['amod_de.lock', 'zmod_de.lock'], $observed_order);
    }

    /**
     * The flip side: a module WITHOUT an overlay when insertLanguage() makes that locking decision is
     * never locked (Konzept: no lock file is created for a module that has no overlay at all). If an
     * overlay for it is created concurrently while the run is still in progress (e.g. an administrator
     * edits that very module through the GUI in between), sync() must refuse to touch it instead of
     * silently discarding it (see MigratedLanguageFileSync's own $expected_overlay_exists) - the
     * module is reported as not-written, and the concurrently-written overlay survives byte for byte.
     * The injected language cache invalidator - called by production code strictly after the database
     * write but before syncMigratedModules() - is (ab)used here as the one available hook to write the
     * "concurrent" overlay at exactly that point, since a single-threaded test cannot literally
     * interleave two calls.
     */
    public function testAModuleWithoutAnOverlayThatGetsOneCreatedDuringTheRunIsReportedAndTheOverlaySurvives(): void
    {
        $this->expectErrorLog();
        $this->shipPo(['greeting' => 'Hallo']);
        // No seedOverlay() call at all - "pilot" starts this run with no overlay whatsoever.
        $concurrent_edit = MigratedPoFixture::catalog(self::MODULE, [
            'greeting' => ['value' => 'Von einem parallelen Admin-Edit', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);

        $manager = $this->manager(null, function () use ($concurrent_edit): void {
            MigratedPoFixture::writePair($this->overlayBase(), $concurrent_edit);
        });

        $unwritten = $manager->insertLanguageForInstallation('de');

        $this->assertSame([self::MODULE], $unwritten);
        $this->assertSame(
            'Von einem parallelen Admin-Edit',
            // untouched, so still as the fixture wrote it: with the module as msgctxt
            $this->overlayPo()->find(self::MODULE, 'greeting')->getTranslation(),
            'the concurrently-written overlay must survive untouched'
        );
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
        // Adapted to the delta overlay: S is served from the shipped .po, only a winning L stays in
        // the overlay (with "original" moved to S)
        $this->assertSame($expected_value, $this->effective()['greeting']['value']);
        if ($expected_value === $shipped) {
            $this->assertFileDoesNotExist($this->overlayBase() . '.po', 'no local change - no overlay');
            return;
        }
        $overlay_entry = $this->overlayPo()->find(null, 'greeting');
        $this->assertSame($expected_value, $overlay_entry->getTranslation());
        $this->assertSame($shipped, LocalChangeComments::getOriginal($overlay_entry), 'original always moves to S');
        $this->assertNotNull(LocalChangeComments::getLocalChange($overlay_entry));
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
        $this->assertNotNull(LocalChangeComments::getLocalChange($this->overlayPo()->find(null, 'greeting')));
    }

    /**
     * The overlay ends up holding ONLY the identifiers the customizing/local file actually mentions -
     * a second, untouched shipped entry of the same module must never end up in the delta merely
     * because the module as a whole received a customizing file.
     */
    public function testACustomizingFileOnlyPutsItsOwnKeysIntoTheDelta(): void
    {
        $this->shipPo(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['pilot#:#greeting#:#Aus Customizing']);

        $this->manager()->insertLanguageForInstallation('de');

        $overlay_identifiers = array_map(
            static fn($entry): string => $entry->getId(),
            $this->overlayPo()->getEntries()
        );
        $this->assertSame(['greeting'], $overlay_identifiers);
        $this->assertSame('Tschüss', $this->lngModules()[self::MODULE]['farewell']);
    }

    /**
     * A customizing (.lang.local) entry whose value has markup TranslationMarkupPolicy does not allow
     * - and that genuinely changes something (differs from both the shipped and the current value,
     * Konzept-Schritt-4) - is left out, not applied; every OTHER entry of the same run is still
     * installed, and the skipped one is reported via getSkippedInvalidMarkupEntries() instead of
     * aborting the whole installation. Uses a non-migrated module ("common") - the customizing-markup
     * check applies independently of the PO/MO pilot.
     */
    public function testACustomizingEntryWithDisallowedMarkupIsSkippedButTheRestIsInstalled(): void
    {
        $this->expectErrorLog();
        $this->writeLang('lang/ilias_de.lang', ['common#:#yes#:#Ja', 'common#:#bad#:#Shipped harmless']);
        $this->writeLang('lang/customizing/ilias_de.lang.local', [
            'common#:#yes#:#Jawohl',
            'common#:#bad#:#<script>evil</script>',
        ]);

        $manager = $this->manager();
        $unwritten = $manager->insertLanguageForInstallation('de');

        $this->assertSame([], $unwritten, 'a skipped customizing entry must not itself count as an unwritten overlay');
        $this->assertSame('Jawohl', $this->lngModules()['common']['yes'], 'the valid customizing entry is applied');
        $this->assertSame(
            'Shipped harmless',
            $this->lngModules()['common']['bad'],
            'the invalid customizing entry is left out - the shipped value stays in effect'
        );
        $skipped = $manager->getSkippedInvalidMarkupEntries();
        $this->assertArrayHasKey('de', $skipped);
        $this->assertArrayHasKey('common#:#bad', $skipped['de']);
        $this->assertNotEmpty($skipped['de']['common#:#bad']);
        $this->assertArrayNotHasKey('common#:#yes', $skipped['de']);
    }

    /**
     * getSkippedInvalidMarkupEntries() reports only what the LAST insertLanguage...() call for a given
     * language left out - a fixed (or removed) customizing file must make the report empty again on
     * the next run, not keep accumulating a stale entry from an earlier run forever.
     */
    public function testGetSkippedInvalidMarkupEntriesIsResetOnEveryInsertLanguageCall(): void
    {
        $this->expectErrorLog();
        $this->writeLang('lang/ilias_de.lang', ['common#:#bad#:#Shipped harmless']);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['common#:#bad#:#<script>evil</script>']);
        $manager = $this->manager();
        $manager->insertLanguageForInstallation('de');
        $this->assertNotEmpty($manager->getSkippedInvalidMarkupEntries()['de'] ?? []);

        // the customizing file is fixed (no more invalid markup) and the language is installed again
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['common#:#bad#:#Jetzt gültig']);
        $manager->insertLanguageForInstallation('de');

        $this->assertArrayNotHasKey('de', $manager->getSkippedInvalidMarkupEntries());
        $this->assertSame('Jetzt gültig', $this->lngModules()['common']['bad']);
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
        $this->assertNull($this->overlayPo()->find(null, 'orphan'), 'neither shipped nor local: dropped');
        $this->assertSame(
            '2025-05-05T05:05:05Z',
            LocalChangeComments::getLocalChange($this->overlayPo()->find(null, 'greeting')),
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
        $custom = $this->overlayPo()->find(null, 'custom');
        $this->assertNull(LocalChangeComments::getOriginal($custom));
        $this->assertNotNull(LocalChangeComments::getLocalChange($custom));
    }

    // ------------------------------------- a key dropped from the shipped po

    /**
     * Catches: removing/weakening the `$previously_shipped_value !== null && $local_value ===
     * $previously_shipped_value` guard in resolveMigratedModule()'s local-entries loop (~729-734), or
     * dropping/breaking the per-module "DELETE FROM lng_data ... AND identifier IN (...)" it triggers
     * (~614-621) - either would leave a stale, no-longer-shipped entry behind forever (in lng_data,
     * lng_modules and the overlay). Exercised via insertLanguageForInstallation(), i.e. an update.
     */
    public function testAKeyDroppedFromTheShippedPoIsRemovedWhenItsLocalValueMatchesTheOriginal(): void
    {
        $this->shipPo(['farewell' => 'Tschüss']); // 'greeting' is no longer shipped
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);
        $this->db_local_changes = [self::MODULE => ['greeting' => 'Hallo']];

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertArrayNotHasKey('greeting', $this->lngModules()[self::MODULE]);
        $this->assertSame(['greeting'], $this->droppedIdentifiers(self::MODULE));
        // Adapted to the delta overlay: nothing local is left, so the overlay is removed completely
        $this->assertFileDoesNotExist($this->overlayBase() . '.po', 'dropped from the overlay as well');
    }

    /**
     * Catches: dropping every no-longer-shipped local entry unconditionally instead of only the ones
     * whose local value still equals the previously shipped one - a real local change must survive a
     * key disappearing from the shipped `.po`.
     */
    public function testAKeyDroppedFromTheShippedPoButWithARealLocalChangeIsKept(): void
    {
        $this->shipPo(['farewell' => 'Tschüss']);
        $this->seedOverlay(['greeting' => [
            'value' => 'Servus',
            'original' => 'Hallo',
            'local_change' => '2025-05-05T05:05:05Z',
        ]]);
        $this->db_local_changes = [self::MODULE => ['greeting' => 'Servus']];

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame('Servus', $this->lngModules()[self::MODULE]['greeting']);
        $this->assertSame([], $this->droppedIdentifiers(self::MODULE), 'a real local change is never dropped');
        $this->assertSame('Servus', $this->overlayPo()->find(null, 'greeting')->getTranslation());
    }

    /**
     * Catches: treating a corrupt (unreadable) overlay as if it reported L === O, dropping a
     * no-longer-shipped local entry the code cannot actually make any three-way decision about.
     */
    public function testAKeyDroppedFromTheShippedPoWithACorruptOverlayIsKept(): void
    {
        $this->expectErrorLog();
        $this->shipPo(['farewell' => 'Tschüss']);
        $this->seedOverlay(['farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss']]);
        file_put_contents($this->overlayBase() . '.po', "msgid \"kaputt\n");
        $this->db_local_changes = [self::MODULE => ['greeting' => 'Hallo']];

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame('Hallo', $this->lngModules()[self::MODULE]['greeting']);
        $this->assertSame([], $this->droppedIdentifiers(self::MODULE));
    }

    /**
     * Catches: the customized-guard being skipped in the local-entries loop, so a customizing value
     * that happens to equal the previously shipped one gets dropped like an ordinary unchanged entry
     * once its key disappears from the shipped `.po`. The customizing value is deliberately chosen
     * equal to the overlay's preserved "original" - the exact condition the (non-customizing) drop
     * logic reacts to - so only the $customized guard can be saving it here.
     *
     * Not asserted: the overlay's LocalChangeComments local-change marker for this entry -
     * LocalChangeComments::refresh() does not set it when the written value equals the preserved
     * "original" (here 'Hallo' === 'Hallo'), even though lng_data's local_change column is set
     * regardless for anything from the customizing file; a separate, intentional bookkeeping quirk.
     */
    public function testACustomizingValueForAKeyDroppedFromTheShippedPoStaysLocal(): void
    {
        $this->shipPo(['farewell' => 'Tschüss']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['pilot#:#greeting#:#Hallo']);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame('Hallo', $this->lngModules()[self::MODULE]['greeting']);
        $this->assertSame(['value' => 'Hallo', 'local_change' => self::NOW], $this->lngData()['pilot|greeting']);
        $this->assertSame([], $this->droppedIdentifiers(self::MODULE));
    }

    /**
     * Catches: MigratedLanguageFileSync::sync() re-taking (or losing) "original" from the shipped
     * `.po` for an entry the `.po` currently does not contain (~185-192 there) - without the
     * preserved O, the three-way decision made once the key ships again would see no O and wrongly
     * keep the stale local value instead of correctly taking over the newly shipped one.
     */
    public function testAKeyThatReturnsAfterBeingUnshippedUsesThePreservedOriginalOnUpdate(): void
    {
        $this->shipPo(['farewell' => 'Tschüss']); // 'greeting' not shipped this run
        $this->seedOverlay(['greeting' => [
            'value' => 'Angepasst',
            'original' => 'Hallo',
            'local_change' => '2025-05-05T05:05:05Z',
        ]]);
        $this->db_local_changes = [self::MODULE => ['greeting' => 'Angepasst']];

        $this->manager()->insertLanguageForInstallation('de');
        $this->assertSame(
            'Angepasst',
            $this->lngModules()[self::MODULE]['greeting'],
            'sanity: kept in run 1 because L != O'
        );

        $this->queries = [];
        $this->shipPo(['greeting' => 'Neu', 'farewell' => 'Tschüss']); // the key ships again, with a new value
        $this->db_local_changes = [self::MODULE => ['greeting' => 'Hallo']]; // reverted to the (preserved) original

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(
            'Neu',
            $this->lngModules()[self::MODULE]['greeting'],
            'L === preserved O, only S changed -> S wins, thanks to the original surviving the unshipped run'
        );
        $this->assertSame(['value' => 'Neu', 'local_change' => null], $this->lngData()['pilot|greeting']);
    }

    /**
     * The whole shipped `.po` missing (not just one key, see the test above) and later coming back:
     * while it is missing, the module is not migrated at all, so it falls back to the plain `.lang`
     * file like any other module (MigratedLanguageFileSync::sync() itself is a no-op then, see
     * MigratedLanguageFileSyncTest::testAnExistingOverlayIsLeftUntouchedWhenTheShippedPoNoLongerExists())
     * - the overlay is left byte-for-byte untouched even though an update runs during the gap. Once
     * the `.po` returns, the next update reconciles three-way per key against the overlay's preserved
     * "original", using three keys to make sure a fix narrowed to only one case would still be caught:
     * - A: the shipped value changed (O preserved, L === O) -> takes the new shipped value;
     * - B: the shipped value is unchanged -> stays as it was;
     * - C: a real local change (L differs from both O and the reshipped S) -> is kept.
     *
     * C also pins the documented known limitation (README, "a local change that only exists in the
     * kept overlay ... comes back with the next update"): while the `.po` is missing, C's `lng_data`
     * row is written like an ordinary (non-local) `.lang` line - the database loses track of it being
     * a local change - yet the overlay still remembers it, so the very next update resurrects it as
     * local again. This pins today's accepted behaviour so a later, deliberate change to it is
     * noticed here instead of silently.
     */
    public function testAWholeMissingShippedPoIsBridgedByThePreservedOverlayAcrossTwoUpdates(): void
    {
        $this->seedOverlay([
            'A' => ['value' => 'A1', 'original' => 'A1'],
            'B' => ['value' => 'B1', 'original' => 'B1'],
            'C' => ['value' => 'C_custom', 'original' => 'C1', 'local_change' => '2025-01-01T00:00:00Z'],
        ]);
        $po_before_the_gap = file_get_contents($this->overlayBase() . '.po');
        $mo_before_the_gap = file_get_contents($this->overlayBase() . '.mo');
        // no shipped `.po` for the whole gap: the module falls back to its plain `.lang` file, exactly
        // reflecting what is currently stored/overlaid
        $this->writeLang('components/pilot/lang/ilias_de.lang', ['A#:#A1', 'B#:#B1', 'C#:#C_custom']);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(
            ['A' => 'A1', 'B' => 'B1', 'C' => 'C_custom'],
            $this->lngModules()[self::MODULE],
            'sanity: the .lang fallback is used while the .po is missing'
        );
        $this->assertSame(
            ['value' => 'C_custom', 'local_change' => null],
            $this->lngData()['pilot|C'],
            'the DB no longer sees C as a local change while the module is not migrated'
        );
        $this->assertSame($po_before_the_gap, file_get_contents($this->overlayBase() . '.po'), 'the overlay is untouched during the gap');
        $this->assertSame($mo_before_the_gap, file_get_contents($this->overlayBase() . '.mo'), 'the overlay is untouched during the gap');

        // the module is migrated again - A's shipped value changed, B's did not, C is not shipped
        // with the (never-shipped) customized value at all
        $this->queries = [];
        $this->shipPo(['A' => 'A2', 'B' => 'B1', 'C' => 'C1']);

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(
            ['C' => 'C_custom', 'A' => 'A2', 'B' => 'B1'],
            $this->lngModules()[self::MODULE]
        );
        $this->assertSame(['value' => 'A2', 'local_change' => null], $this->lngData()['pilot|A'], 'L === preserved O -> S wins');
        $this->assertSame(['value' => 'B1', 'local_change' => null], $this->lngData()['pilot|B'], 'unchanged');
        $this->assertSame(
            ['value' => 'C_custom', 'local_change' => '2025-01-01 00:00:00'],
            $this->lngData()['pilot|C'],
            'the overlay-only local change is taken over again - the known limitation above'
        );
        // Adapted to the delta overlay: A and B carry the shipped values and leave the (full, legacy)
        // overlay, only the local change C stays
        $overlay = $this->overlayPo();
        $this->assertNull($overlay->find(null, 'A'));
        $this->assertNull($overlay->find(null, 'B'));
        $this->assertSame('A2', $this->effective()['A']['value']);
        $this->assertSame('C_custom', $overlay->find(null, 'C')->getTranslation());
        $this->assertNotNull(LocalChangeComments::getLocalChange($overlay->find(null, 'C')));
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
        // Adapted to the delta overlay: "remove local changes" leaves no local change - the overlay
        // is removed (was: rebuilt from the shipped .po)
        $this->assertFileDoesNotExist($this->overlayBase() . '.po');
        $this->assertFileDoesNotExist($this->overlayBase() . '.mo');
        $effective = $this->effective();
        $this->assertSame(['greeting', 'farewell'], array_keys($effective), 'the locally added variable is gone');
        $this->assertSame('Hallo', $effective['greeting']['value']);
        $this->assertFalse($effective['greeting']['local_change']);
    }

    /**
     * Applying local changes reconciles a migrated module with its shipped `.po` like an update:
     * the not locally changed entries are rewritten from the shipped `.po` (lng_data rows without
     * local_change), the customizing file goes on top as local change and the overlay follows.
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

        $this->assertSame(
            [
                'pilot|greeting' => ['value' => 'Grüß Gott', 'local_change' => self::NOW],
                'pilot|farewell' => ['value' => 'Tschüss', 'local_change' => null],
            ],
            $this->lngData()
        );
        $this->assertSame(
            [self::MODULE => ['greeting' => 'Grüß Gott', 'farewell' => 'Tschüss'], 'common' => ['yes' => 'Ja']],
            $this->lngModules()
        );
        $greeting = $this->overlayPo()->find(null, 'greeting');
        $this->assertSame('Grüß Gott', $greeting->getTranslation());
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($greeting));
        $this->assertNotNull(LocalChangeComments::getLocalChange($greeting));
    }

    /**
     * Catches: dropping/weakening the pre-INSERT "DELETE FROM lng_data WHERE ... local_change IS
     * NULL AND module IN (...)" ($delete_unchanged_migrated_rows, ~599-605) that
     * insertLanguageForApplyingLocalChanges() relies on instead of flushing first (~337-359): without
     * it, a shipped key dropped from the `.po` between two "apply local changes" runs keeps its stale
     * row forever. Combined in one run with a real local change, an overlay-only local change, an
     * updated-but-unchanged entry and a non-migrated module, so a fix narrowed to only one of them
     * would still be caught.
     */
    public function testApplyingLocalChangesReconcilesEveryCaseInOneSweepAndPurgesTheStaleUnchangedRow(): void
    {
        $this->shipPo(['greeting' => 'Hallo', 'farewell' => 'Tschüss', 'obsolete' => 'Alt']);
        $this->manager()->insertLanguageForInstallation('de');
        $this->assertSame(
            ['value' => 'Alt', 'local_change' => null],
            $this->lngData()['pilot|obsolete'],
            'sanity: the row exists after the first install'
        );

        $this->shipPo(['greeting' => 'Servus', 'farewell' => 'Pfiat di']); // 'obsolete' dropped from the shipped po
        $this->seedOverlay([
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'farewell' => ['value' => 'Auf Wiedersehen', 'original' => 'Tschüss', 'local_change' => '2025-04-04T04:04:04Z'],
            'added_locally' => ['value' => 'Eigene Var', 'local_change' => '2025-03-03T03:03:03Z'],
        ]);
        $this->db_local_changes = [self::MODULE => ['farewell' => 'Auf Wiedersehen']];
        $this->db_entries = [
            'common' => ['yes' => 'Ja', 'no' => 'Nein'],
            self::MODULE => ['greeting' => 'Hallo', 'farewell' => 'Tschüss', 'obsolete' => 'Alt'],
        ];

        $this->manager()->insertLanguageForApplyingLocalChanges('de');

        $this->assertSame(
            'Servus',
            $this->lngModules()[self::MODULE]['greeting'],
            'stored "Hallo", shipped now "Servus" -> "Servus"'
        );
        $this->assertSame(
            'Auf Wiedersehen',
            $this->lngModules()[self::MODULE]['farewell'],
            'a real local change (L != S != O) is kept'
        );
        $this->assertSame(
            'Eigene Var',
            $this->lngModules()[self::MODULE]['added_locally'],
            'an overlay-only local change is taken over'
        );
        $this->assertArrayNotHasKey('obsolete', $this->lngModules()[self::MODULE]);
        $this->assertSame(
            ['yes' => 'Ja', 'no' => 'Nein'],
            $this->lngModules()['common'],
            'a non-migrated module in the seed stays complete'
        );

        $this->assertSame(['value' => 'Servus', 'local_change' => null], $this->lngData()['pilot|greeting']);
        $this->assertSame(
            ['value' => 'Eigene Var', 'local_change' => '2025-03-03 03:03:03'],
            $this->lngData()['pilot|added_locally']
        );
        $this->assertArrayNotHasKey(
            'pilot|obsolete',
            $this->lngData(),
            'the DELETE ... local_change IS NULL bulk purge took effect: the stale unchanged row is gone'
        );
        $this->assertNotEmpty(
            array_filter(
                $this->queries,
                static fn(string $q): bool => str_starts_with($q, 'DELETE FROM lng_data')
                    && str_contains($q, 'local_change IS NULL')
                    && str_contains($q, "module IN ('pilot')")
            ),
            'the bulk delete of unchanged migrated rows was actually issued'
        );
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
            $calls[] = [$lang_key, count(array_filter($this->queries, static fn(string $q): bool => str_starts_with($q, /** @lang text */ 'INSERT INTO lng_modules'))), is_file($overlay_po)];
        })->insertLanguageForInstallation('de');

        $this->assertSame([['de', 1, false]], $calls, 'once, after the lng_modules write, before the overlay sync');
    }

    public function testAFailingCacheInvalidatorIsLoggedAndSwallowed(): void
    {
        $this->expectErrorLog();
        $this->shipPo(['greeting' => 'Hallo']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);

        $this->manager(null, static function (): void {
            throw new RuntimeException('cache is down');
        })->insertLanguageForInstallation('de');

        $this->assertSame(['greeting' => 'Hallo'], $this->lngModules()[self::MODULE]);
        // Adapted to the delta overlay: a shipped value writes no file - the sync still running is
        // visible from the removal of an outdated overlay
        $this->assertFileDoesNotExist($this->overlayBase() . '.mo', 'the overlay sync still ran: it removed the outdated overlay');
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
        // a local change: only then an overlay has to be written (delta)
        $this->db_local_changes = [self::MODULE => ['greeting' => 'Servus']];
        rmdir($this->root . '/client-data');
        mkdir($this->root . '/client-data');
        file_put_contents($this->root . '/client-data/lang', 'not a directory');

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $unwritten = $this->manager()->insertLanguageForInstallation('de');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['greeting' => 'Servus'], $this->lngModules()[self::MODULE]);
        $this->assertStringContainsString('Could not sync migrated language file for module "pilot"', $this->errorLog());
        $this->assertSame([self::MODULE], $unwritten, 'the failed module is returned so callers can report it');
    }

    /**
     * The same failure is returned by all three write paths - and only the migrated module whose
     * overlay failed is listed, never a non-migrated one written in the same run.
     */
    #[DataProvider('writePaths')]
    public function testEveryWritePathReturnsOnlyTheModulesWhoseOverlayCouldNotBeWritten(string $method, bool $writes_overlay): void
    {
        if ($writes_overlay) {
            $this->expectErrorLog();
        }
        $this->shipPo(['greeting' => 'Hallo']);
        $this->writeLang('lang/ilias_de.lang', ['common#:#yes#:#Ja']);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['pilot#:#greeting#:#Servus']);
        $this->db_entries = [self::MODULE => ['greeting' => 'Hallo'], 'common' => ['yes' => 'Ja']];
        file_put_contents($this->root . '/client-data/lang', 'not a directory');

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $unwritten = $this->manager()->$method('de');
        } finally {
            restore_error_handler();
        }

        // Adapted to the delta overlay: "remove local changes" leaves only shipped values, so it
        // has no overlay to write (nor one to remove) and nothing can fail
        $this->assertSame($writes_overlay ? [self::MODULE] : [], $unwritten);
    }

    #[DataProvider('writePaths')]
    public function testEveryWritePathReturnsNothingWhenTheOverlayWasWritten(string $method, bool $writes_overlay): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $this->writeLang('lang/ilias_de.lang', ['common#:#yes#:#Ja']);
        $this->writeLang('lang/customizing/ilias_de.lang.local', ['pilot#:#greeting#:#Servus']);
        $this->db_entries = [self::MODULE => ['greeting' => 'Hallo'], 'common' => ['yes' => 'Ja']];

        $this->assertSame([], $this->manager()->$method('de'));
        // Adapted to the delta overlay: only a path that keeps the customizing value writes one
        $this->assertSame($writes_overlay, is_file($this->overlayBase() . '.mo'));
    }

    public static function writePaths(): array
    {
        return [
            'install/update' => ['insertLanguageForInstallation', true],
            'remove local changes' => ['insertLanguageForRemovingLocalChanges', false],
            'apply local changes' => ['insertLanguageForApplyingLocalChanges', true],
        ];
    }

    /**
     * No overlay is maintained without client data directory - that is not a failure to report.
     */
    public function testWithoutClientDataDirNothingIsReturnedAsUnwritten(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);

        $this->assertSame([], $this->manager(static fn(): ?string => null)->insertLanguageForInstallation('de'));
    }

    // ------------------------------------------- findUnwritableOverlayDirectories

    /**
     * Checked against the client data directory the instance maintains the overlay in (resolved at
     * call time), for the migrated modules of the given languages only.
     */
    public function testFindUnwritableOverlayDirectoriesChecksTheResolvedClientDataDir(): void
    {
        $this->shipPo(['greeting' => 'Hallo']);
        $blocked = $this->root . '/blocked';
        mkdir($blocked);
        file_put_contents($blocked . '/lang', 'not a directory');
        $target = $this->root . '/client-data';
        $manager = $this->manager(static function () use (&$target): ?string {
            return $target;
        });

        $this->assertSame([], $manager->findUnwritableOverlayDirectories(['de']));

        $target = $blocked;
        $this->assertSame([$blocked . '/lang/components/pilot/lang'], $manager->findUnwritableOverlayDirectories(['de']));
        $this->assertSame([], $manager->findUnwritableOverlayDirectories(['en']), 'pilot is not migrated for en');

        $target = null;
        $this->assertSame([], $manager->findUnwritableOverlayDirectories(['de']));
    }

    // ------------------------------------------------ multi-line shipped values

    /**
     * Three-way reconciliation with a multi-line shipped value: the overlay "original" (O) is read
     * back losslessly, so a database local change L that equals O is recognised as "only S changed"
     * and S wins - with a flattened O, L !== O would have been kept as a false local change.
     */
    public function testAMultiLineLocalValueEqualToTheOriginalIsReplacedByTheNewShippedValue(): void
    {
        $this->shipPo(['greeting' => "Neu\nZeile 2"]);
        $this->seedOverlay(['greeting' => ['value' => "Alt\r\nZeile 2", 'original' => "Alt\r\nZeile 2"]]);
        $this->db_local_changes = [self::MODULE => ['greeting' => "Alt\r\nZeile 2"]];

        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame("Neu\nZeile 2", $this->lngModules()[self::MODULE]['greeting']);
        $this->assertSame(['value' => "Neu\nZeile 2", 'local_change' => null], $this->lngData()['pilot|greeting']);
        // Adapted to the delta overlay: S wins, so the legacy overlay entry is removed
        $this->assertFileDoesNotExist($this->overlayBase() . '.po');
        $this->assertFalse($this->effective()['greeting']['local_change']);
    }

    /**
     * An unchanged multi-line shipped value without any local change is no local change after an
     * update - neither in lng_data nor in the overlay, also on a second run.
     */
    public function testAnUnchangedMultiLineShippedValueStaysUnchangedAcrossUpdates(): void
    {
        $this->shipPo(['greeting' => "Zeile 1\nZeile 2 mit C:\\pfad"]);

        $this->manager()->insertLanguageForInstallation('de');
        $this->queries = [];
        $this->manager()->insertLanguageForInstallation('de');

        $this->assertSame(['value' => "Zeile 1\nZeile 2 mit C:\\pfad", 'local_change' => null], $this->lngData()['pilot|greeting']);
        // Adapted to the delta overlay: recognised as the shipped value - no overlay on either run
        $this->assertFileDoesNotExist($this->overlayBase() . '.po');
        $this->assertFalse($this->effective()['greeting']['local_change']);
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
        $this->assertSame('Pfiat di', $overlay->find(null, 'farewell')->getTranslation());
        $this->assertSame('Tschüss', LocalChangeComments::getOriginal($overlay->find(null, 'farewell')));
        // Adapted to the delta overlay: "greeting" carries the shipped value and is not written
        $this->assertSame(['farewell' => 'Pfiat di'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
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
        // a local change, so there is an overlay to write (delta)
        $this->db_local_changes = [self::MODULE => ['greeting' => 'Servus']];
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
