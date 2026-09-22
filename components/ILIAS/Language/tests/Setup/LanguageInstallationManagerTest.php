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
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Language\Setup\LanguageInstallationManager;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for LanguageInstallationManager. In particular, these
 * tests pin down last_update handling for object_data bookkeeping rows: the
 * UPDATE path (registerInstalledLanguage()'s update branch, and
 * installLanguages()' uninstall branch) sets last_update via the manager's
 * injectable clock, so a test can assert the exact value instead of only
 * that *some* value was set - while the INSERT path deliberately keeps using
 * $db->now() instead, which is asserted explicitly as well. The clock exists
 * for testability, not because the date was ever computed incorrectly.
 */
class LanguageInstallationManagerTest extends TestCase
{
    /**
     * Set by seedMigratedFixtureModule() - a throwaway .po/.mo fixture pair, living under the
     * caller's own $root (see that method's docblock for why: MigratedLanguageFileSync::sync() now
     * resolves a migrated module's files against the LanguageInstallationManager's own injected
     * $absolute_path, not a global constant). $root is always a temp directory each test removes
     * itself via removeDirectory($root) in its own finally block, which takes this fixture directory
     * (nested inside $root) down with it - no separate cleanup needed here.
     */
    private ?string $fixture_directory = null;

    private function createDatabaseMock(): MockObject&ilDBInterface
    {
        $db = $this->createMock(ilDBInterface::class);
        $db->method('nextId')->willReturn(42);
        $db->method('quote')->willReturnCallback(
            static fn(mixed $value): string => "'" . (string) $value . "'"
        );
        $db->method('now')->willReturn('NOW()');
        $db->method('manipulate')->willReturn(1);

        return $db;
    }

    private function createManager(
        ilDBInterface $db,
        InstalledLanguageRepository $repository,
        ?\DateTimeImmutable $now = null
    ): LanguageInstallationManager {
        return new LanguageInstallationManager(
            $db,
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                new MainLanguageFileDirectory()
            ),
            (string) realpath(__DIR__ . '/../../../../../'),
            $repository,
            $now !== null ? static fn(): \DateTimeImmutable => $now : null
        );
    }

    public function testRegisterInstalledLanguageUpdateUsesInjectedClockForLastUpdate(): void
    {
        $db = $this->createDatabaseMock();
        $db->expects($this->once())
            ->method('manipulate')
            ->with($this->logicalAnd(
                $this->stringContains(/** @lang text */ 'UPDATE object_data'),
                $this->stringContains("last_update = '2026-01-02 03:04:05'")
            ));

        $manager = $this->createManager(
            $db,
            $this->createStub(InstalledLanguageRepository::class),
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))
        );

        $manager->registerInstalledLanguage(
            'de',
            ['de' => ['obj_id' => 7, 'status' => 'not_installed']],
            []
        );
    }

    public function testRegisterInstalledLanguageInsertUsesDbNowNotTheClock(): void
    {
        $db = $this->createDatabaseMock();
        $db->expects($this->once())
            ->method('manipulate')
            ->with($this->logicalAnd(
                $this->stringContains(/** @lang text */ 'INSERT INTO object_data'),
                $this->stringContains('NOW(),NOW()')
            ));

        $manager = $this->createManager(
            $db,
            $this->createStub(InstalledLanguageRepository::class),
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))
        );

        $manager->registerInstalledLanguage('de', [], []);
    }

    public function testInstallLanguagesSetsLastUpdateOnUninstallUsingInjectedClock(): void
    {
        $db = $this->createDatabaseMock();
        $calls = [];
        $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
            $calls[] = $query;
            return 1;
        });

        $repository = $this->createStub(InstalledLanguageRepository::class);
        $repository->method('getAvailableLanguages')->willReturn([
            'fr' => ['obj_id' => 3, 'status' => 'installed'],
        ]);
        $repository->method('getLocalLanguages')->willReturn([]);

        $manager = $this->createManager(
            $db,
            $repository,
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))
        );

        // 'fr' was installed but is not part of the requested keys anymore -
        // it must be flushed and its bookkeeping row flipped to
        // "not_installed" with a freshly set last_update.
        $result = $manager->installLanguages([]);

        $this->assertTrue($result);
        $bookkeeping_update = array_values(array_filter(
            $calls,
            static fn(string $query): bool => str_contains($query, /** @lang text */ 'UPDATE object_data')
        ));
        $this->assertCount(1, $bookkeeping_update);
        $this->assertStringContainsString("description = 'not_installed'", $bookkeeping_update[0]);
        $this->assertStringContainsString("last_update = '2026-01-02 03:04:05'", $bookkeeping_update[0]);
    }

    public function testFlushLanguageForInstallationKeepsLocalChanges(): void
    {
        $db = $this->createDatabaseMock();
        $db->expects($this->once())
            ->method('manipulate')
            ->with($this->logicalAnd(
                $this->stringContains(/** @lang text */ 'DELETE FROM lng_data'),
                $this->stringContains('local_change IS NULL')
            ));

        $manager = $this->createManager($db, $this->createStub(InstalledLanguageRepository::class));

        $manager->flushLanguageForInstallation('de');
    }

    public function testFlushLanguageForUninstallationDeletesEverything(): void
    {
        $db = $this->createDatabaseMock();
        $calls = [];
        $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
            $calls[] = $query;
            return 1;
        });

        $manager = $this->createManager($db, $this->createStub(InstalledLanguageRepository::class));

        $manager->flushLanguageForUninstallation('de');

        $this->assertCount(2, $calls);
        $this->assertStringContainsString(/** @lang text */ 'DELETE FROM lng_data', $calls[0]);
        $this->assertStringNotContainsString('local_change IS NULL', $calls[0]);
        $this->assertStringContainsString(/** @lang text */ 'DELETE FROM lng_modules', $calls[1]);
    }

    /**
     * Regression coverage for the "uninstall changes" bug: reinstalling a
     * language while removing its local changes must not re-apply the
     * customizing/local directory's file - otherwise the very data the
     * action is asked to remove is immediately reinstated (see
     * ilObjLanguageFolderGUI::uninstallChangesObject() and
     * ilObjLanguage::removeLocalChanges()).
     */
    public function testInsertLanguageForRemovingLocalChangesIgnoresCustomizingDirectory(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [['common', 'test', 'Global Value']]);
        $this->writeLangFile($root . '/lang/customizing/ilias_de.lang.local', [['common', 'test', 'Custom Value']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createMock(InstalledLanguageRepository::class);
            // No DB local changes must be consulted at all - a clean re-seed
            // from the global files only has nothing to preserve/merge.
            $repository->expects($this->never())->method('getLocalChanges');

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $manager->insertLanguageForRemovingLocalChanges('de');

            $insert = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')
            ));
            $this->assertCount(1, $insert);
            $this->assertStringContainsString("'Global Value'", $insert[0]);
            $this->assertStringNotContainsString('Custom Value', $insert[0]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Sanity check that the ordinary installation path is unaffected by the
     * split above: it must keep merging the customizing/local directory's
     * file on top of the global one.
     */
    public function testInsertLanguageForInstallationStillMergesCustomizingDirectory(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [['common', 'test', 'Global Value']]);
        $this->writeLangFile($root . '/lang/customizing/ilias_de.lang.local', [['common', 'test', 'Custom Value']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository,
                static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))
            );

            $manager->insertLanguageForInstallation('de');

            $insert = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')
            ));
            $this->assertCount(1, $insert);
            $this->assertStringContainsString("'Global Value'", $insert[0]);
            $this->assertStringContainsString("'Custom Value'", $insert[0]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * insertLanguage() used to ask the repository for the local changes once
     * per *line* of a customizing file, which meant thousands of identical
     * "SELECT ... FROM lng_data" queries for a realistically sized file. The
     * lookup depends only on the language and the file's mtime, and nothing
     * inside the loop writes to the database, so it is resolved once per
     * directory now. This pins the query count down - it must not grow with
     * the file - while the assertions below keep the precedence rules that
     * the lookup exists for.
     */
    public function testLocalChangesAreLookedUpOncePerDirectoryNotPerEntry(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [
            ['common', 'locally_changed', 'Global Value'],
            ['common', 'plain', 'Plain Global'],
        ]);

        $local_entries = [['common', 'newer_in_db', 'From Local File']];
        for ($i = 0; $i < 200; $i++) {
            $local_entries[] = ['common', 'override_' . $i, 'Local ' . $i];
        }
        $this->writeLangFile($root . '/lang/customizing/ilias_de.lang.local', $local_entries);

        try {
            $calls = 0;
            $inserts = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(
                static function (string $query) use (&$inserts): int {
                    if (str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')) {
                        $inserts[] = $query;
                    }
                    return 1;
                }
            );

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturnCallback(
                static function (string $lang_key, string $min_date = '') use (&$calls): array {
                    $calls++;
                    // No date given: the seed of changes already in the database.
                    // With a date: changes newer than the customizing file.
                    return $min_date === ''
                        ? ['common' => ['locally_changed' => 'DB Local Change']]
                        : ['common' => ['newer_in_db' => 'DB Is Newer']];
                }
            );

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository,
                static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))
            );

            $manager->insertLanguageForInstallation('de');

            // One seed lookup plus one for the single customizing directory -
            // independent of the 201 entries in that file.
            $this->assertSame(2, $calls);

            $this->assertCount(1, $inserts);
            $insert = $inserts[0];
            // A global entry with a local change in the database keeps the
            // database value, so it is not re-inserted from the global file.
            $this->assertStringNotContainsString("'Global Value'", $insert);
            $this->assertStringContainsString("'Plain Global'", $insert);
            // A customizing entry the database has an even newer change for
            // must not be overwritten by the file either.
            $this->assertStringNotContainsString("'From Local File'", $insert);
            $this->assertStringContainsString("'Local 0'", $insert);
            $this->assertStringContainsString("'Local 199'", $insert);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * insertLanguageForApplyingLocalChanges() must read only the
     * customizing/local directory - the base/global directory's content
     * must never appear in the resulting INSERT, and no flush (DELETE FROM
     * lng_data) may happen at all, since the base data is left untouched.
     */
    public function testInsertLanguageForApplyingLocalChangesOnlyReadsCustomizingDirectory(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [['common', 'test', 'Global Value']]);
        $this->writeLangFile($root . '/lang/customizing/ilias_de.lang.local', [['common', 'test', 'Custom Value']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createMock(InstalledLanguageRepository::class);
            $repository->expects($this->once())
                ->method('getLanguageEntries')
                ->with('de')
                ->willReturn([]);
            // insertLanguage()'s shared "does the DB hold an even newer
            // change than this local file?" check still calls
            // getLocalChanges() once per local directory - that is unrelated
            // to the *seed*, which must come from getLanguageEntries() alone.
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $manager->insertLanguageForApplyingLocalChanges('de');

            $insert = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')
            ));
            $this->assertCount(1, $insert);
            $this->assertStringContainsString("'Custom Value'", $insert[0]);
            $this->assertStringNotContainsString('Global Value', $insert[0]);

            $flush = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'DELETE FROM lng_data')
            ));
            $this->assertCount(0, $flush);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Regression coverage for the lng_modules cache correctness that the
     * choice of seed exists for: insertLanguage() rebuilds the lng_modules
     * cache row for a module entirely from the seed plus whatever it reads
     * from the given directories. If the seed only contained previously
     * *local* changes (getLocalChanges()) instead of every currently stored
     * entry (getLanguageEntries()), a plain base entry the customizing file
     * does not override would silently vanish from the rebuilt cache row -
     * this pins that it survives.
     */
    public function testInsertLanguageForApplyingLocalChangesPreservesUnrelatedSeedEntriesInModulesCache(): void
    {
        $root = $this->createTempInstallationRoot();
        // Only 'overridden' is touched by the customizing file below -
        // 'untouched_by_customizing' must still make it into lng_modules,
        // because getLanguageEntries() (the seed) already contains it.
        $this->writeLangFile(
            $root . '/lang/customizing/ilias_de.lang.local',
            [['common', 'overridden', 'New Custom Value']]
        );

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLanguageEntries')->willReturnMap([
                ['de', [
                    'common' => [
                        'overridden' => 'Old Value',
                        'untouched_by_customizing' => 'Still Here',
                    ],
                ]],
            ]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $manager->insertLanguageForApplyingLocalChanges('de');

            $modules_insert = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_modules')
            ));
            $this->assertCount(1, $modules_insert);
            $serialized = serialize([
                'overridden' => 'New Custom Value',
                'untouched_by_customizing' => 'Still Here',
            ]);
            $this->assertStringContainsString($serialized, $modules_insert[0]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Regression coverage for the "brand new language" path: installLanguages()
     * must register a language that passes validation and is not yet known in
     * object_data, using registerInstalledLanguage()'s INSERT branch. Only the
     * uninstall branch (empty $lang_keys) was previously covered.
     */
    public function testInstallLanguagesRegistersBrandNewLanguage(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [['common', 'greeting', 'Hallo']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('checkLanguage')->willReturnMap([
                ['de', true],
            ]);
            $repository->method('getAvailableLanguages')->willReturn([]);
            $repository->method('getLocalLanguages')->willReturn([]);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $result = $manager->installLanguages(['de']);

            $this->assertTrue($result);

            $inserts_object_data = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO object_data')
            ));
            $this->assertCount(1, $inserts_object_data);
            $this->assertStringContainsString("'de'", $inserts_object_data[0]);
            $this->assertStringContainsString("'installed'", $inserts_object_data[0]);
            $this->assertStringNotContainsString("'installed_local'", $inserts_object_data[0]);

            $keep_local_flush = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_contains($query, /** @lang text */ 'DELETE FROM lng_data')
                    && str_contains($query, 'local_change IS NULL')
            ));
            $this->assertCount(1, $keep_local_flush);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Regression coverage for validation failures: a language that fails
     * checkLanguage() must be reported as failed (a list, not `true`) and must
     * be completely skipped in the second pass - neither re-synced nor
     * flushed - even though it is already known in object_data. Only the
     * "all languages pass" and "none are requested" cases were previously
     * covered.
     */
    public function testInstallLanguagesReportsFailedLanguageAndLeavesItUntouched(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [['common', 'greeting', 'Hallo']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('checkLanguage')->willReturnMap([
                ['de', true],
                ['xx', false],
            ]);
            $repository->method('getAvailableLanguages')->willReturn([
                // Already known from a previous installation; its language
                // file has since become invalid (e.g. removed/corrupted).
                'xx' => ['obj_id' => 5, 'status' => 'installed'],
            ]);
            $repository->method('getLocalLanguages')->willReturn([]);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $result = $manager->installLanguages(['de', 'xx']);

            // A list of the failing keys, not `true`.
            $this->assertSame(['xx'], $result);

            // 'de' installs normally.
            $inserts_object_data = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO object_data')
            ));
            $this->assertCount(1, $inserts_object_data);
            $this->assertStringContainsString("'de'", $inserts_object_data[0]);

            // 'xx' is already known (obj_id 5) but failed validation this
            // time - it must not be touched at all: no flush, no status
            // update, no resync.
            foreach ($calls as $query) {
                $this->assertStringNotContainsString("obj_id = '5'", $query);
                $this->assertStringNotContainsString("lang_key = 'xx'", $query);
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Regression coverage for the "resync" branch: a language that is
     * already known in object_data and is still requested must have its
     * bookkeeping row refreshed via registerInstalledLanguage() - without
     * being fully flushed (flushLanguage("all")) or having its status forced
     * to "not_installed". Previously only the "no longer requested"
     * (uninstall) branch of this second loop was covered.
     */
    public function testInstallLanguagesResyncsAlreadyKnownRequestedLanguage(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_fr.lang', [['common', 'greeting', 'Bonjour']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('checkLanguage')->willReturn(true);
            $repository->method('getAvailableLanguages')->willReturn([
                'fr' => ['obj_id' => 9, 'status' => 'installed'],
            ]);
            $repository->method('getLocalLanguages')->willReturn([]);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $result = $manager->installLanguages(['fr']);
            $this->assertTrue($result);

            // Resync must not fully flush the language (flushLanguage("all")
            // deletes with no module filter at all; insertLanguage's own
            // per-module cleanup, which does run, always carries an
            // "AND module IN (...)" filter and is a different query).
            $full_flush_deletes = array_values(array_filter(
                $calls,
                static fn(string $query): bool => $query === "DELETE FROM lng_modules WHERE lang_key = 'fr'"
            ));
            $this->assertCount(0, $full_flush_deletes);

            // ...and must not force its status to "not_installed"...
            foreach ($calls as $query) {
                $this->assertStringNotContainsString('not_installed', $query);
            }

            // ...but does refresh the bookkeeping row via an UPDATE, keeping
            // the "installed" status.
            $object_data_updates = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'UPDATE object_data')
            ));
            $this->assertCount(1, $object_data_updates);
            $this->assertStringContainsString("obj_id = '9'", $object_data_updates[0]);
            $this->assertStringContainsString("description = 'installed'", $object_data_updates[0]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Regression coverage for the uninstall branch's status-update guard: a
     * language already marked "not_installed" (not merely absent from
     * $lang_keys, but explicitly already flagged as such) must still be
     * flushed, but must NOT receive a redundant
     * "UPDATE ... description = 'not_installed'". strpos("not_installed",
     * "installed") is 3, not 0/false - this pins the exact `=== 0` boundary
     * check against a `!== false` or `>= 0` mutation, either of which would
     * wrongly re-fire the UPDATE for an already-"not_installed" row.
     */
    public function testInstallLanguagesDoesNotReUpdateAlreadyNotInstalledLanguage(): void
    {
        $db = $this->createDatabaseMock();
        $calls = [];
        $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
            $calls[] = $query;
            return 1;
        });

        $repository = $this->createStub(InstalledLanguageRepository::class);
        $repository->method('getAvailableLanguages')->willReturn([
            'de' => ['obj_id' => 4, 'status' => 'not_installed'],
        ]);
        $repository->method('getLocalLanguages')->willReturn([]);

        $manager = $this->createManager($db, $repository);

        $result = $manager->installLanguages([]);

        $this->assertTrue($result);

        // Still flushed (uninstall path)...
        $this->assertStringContainsString(
            "DELETE FROM lng_modules WHERE lang_key = 'de'",
            implode("\n", $calls)
        );

        // ...but no UPDATE at all: it was already "not_installed", so there
        // is nothing to change.
        $updates = array_values(array_filter(
            $calls,
            static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'UPDATE object_data')
        ));
        $this->assertCount(0, $updates);
    }

    /**
     * Regression coverage for the "remarks" (comment) column: a .lang line
     * may carry an optional "###comment" suffix after the value (see real
     * examples in lang/ilias_en.lang, e.g.
     * "assessment#:#discard_answer#:#Delete Answer###fau: testNav"). The
     * pre-refactor implementation
     * (class.ilObjLanguageDBAccess::insertLangEntries()) extracted that
     * suffix into $separated[3] and wrote it into lng_data.remarks.
     * insertLanguage() still truncates the value at "###" (so the value
     * itself stays clean), but never assigns $separated[3] - the INSERT
     * therefore always quotes `$separated[3] ?? null`, i.e. NULL, silently
     * discarding every comment on every (re-)install/refresh. This pins the
     * expected (pre-refactor) behavior: the comment must survive into the
     * remarks column.
     */
    public function testInsertLanguageStoresCommentSuffixInRemarksColumn(): void
    {
        $root = $this->createTempInstallationRoot();
        file_put_contents(
            $root . '/lang/ilias_de.lang',
            "<!-- language file start -->\n"
                . "assessment#:#discard_answer#:#Delete Answer###fau: testNav\n"
        );

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('assessment')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de');

            $insert = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')
            ));
            $this->assertCount(1, $insert);

            // The value itself must be truncated at "###" - the comment
            // separator (and the comment text after it) must never leak
            // into the value.
            $this->assertStringContainsString("'Delete Answer'", $insert[0]);
            $this->assertStringNotContainsString('Delete Answer###', $insert[0]);

            // The comment itself must be preserved in the remarks column -
            // not silently discarded as NULL.
            $this->assertStringContainsString(
                "'fau: testNav'",
                $insert[0],
                'The "###" comment suffix must be stored in the remarks column of lng_data, not discarded.'
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Boundary/companion to the regression test above: a line with no
     * "###" comment suffix at all must keep leaving the remarks column
     * NULL - this is the pre-existing, correct behavior for the (much more
     * common) case of a plain line, and must not regress either way (e.g.
     * a naive fix that always writes an empty string, or the literal text
     * "null", instead of a real NULL).
     */
    public function testInsertLanguageLeavesRemarksNullWhenLineHasNoCommentSuffix(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [['common', 'test', 'Plain Value']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de');

            $insert = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')
            ));
            $this->assertCount(1, $insert);
            $this->assertStringContainsString("'Plain Value'", $insert[0]);
            // The mock's quote() callback casts every value through
            // (string), so a NULL remarks column surfaces as the tuple
            // ending in an empty quoted string right before the closing
            // paren - this pins that shape rather than a literal "NULL".
            $this->assertMatchesRegularExpression(
                "/,'',''\\)/",
                $insert[0],
                'Expected the tuple to end in (...,local_change=\'\',remarks=\'\') for a line without a comment suffix.'
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * Regression coverage for prefix handling on the write path: a component
     * directory's two-field lines (identifier#:#value, no module) must be
     * written with the directory's prefix as the module - not with the
     * file's own first field misread as the module, nor with the four
     * resulting fields shifted out of place. The read path (checkLanguage())
     * already has prefix coverage in ilSetupLanguageTest; this covers the
     * write path in insertLanguage().
     */
    public function testInsertLanguageUsesDirectoryPrefixAsModuleForComponentFiles(): void
    {
        $root = $this->createTempInstallationRoot();
        mkdir($root . '/lang/file', 0777, true);
        file_put_contents(
            $root . '/lang/file/ilias_de.lang',
            "<!-- language file start -->\nadd_file#:#Add File\n"
        );

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('file')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(
                    new CustomizingLanguageFileDirectory(),
                    new MainLanguageFileDirectory(),
                    $this->createPrefixedComponentDirectory('lang/file/', 'file')
                ),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de');

            $insert = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_data')
            ));
            $this->assertCount(1, $insert);
            // module=prefix, identifier=first field of the 2-field line,
            // value=second field - not the line's own first field as module.
            $this->assertStringContainsString("('file','add_file','de','Add File'", $insert[0]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * insertLanguage()'s mirroring calls MigratedLanguageFileSync::sync() with the manager's own
     * injected $absolute_path (this test's $root) - not a global constant (see
     * MigratedLanguageFileSync::sync()'s docblock on why: LanguageInstallationManager runs in Setup
     * contexts where ILIAS_ABSOLUTE_PATH is not reliably defined). A migrated fixture module must
     * therefore be seeded under the very same $root the LanguageInstallationManager under test is
     * constructed with, not under an unrelated location such as this test file's own directory or a
     * second, independent temp directory.
     *
     * @param array<string, string> $entries identifier => value
     */
    private function seedMigratedFixtureModule(string $root, string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory = rtrim($root, '/') . '/migrated-fixtures';
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $value) {
            $translations->add(Translation::create($module, $identifier)->translate($value));
        }

        $base_path = $this->fixture_directory . '/' . $module . '_' . $lang_key;
        (new PoGenerator())->generateFile($translations, $base_path . '.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $base_path . '.mo');

        return new class ($module, 'migrated-fixtures/') implements LanguageFileDirectory {
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

    private function loadFixturePo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po');
    }

    /**
     * Writes a *.lang source file for a prefixed (component-style) directory under $root - two-field
     * lines (identifier#:#value), matching testInsertLanguageUsesDirectoryPrefixAsModuleForComponentFiles's
     * fixture convention: insertLanguage() unshifts the directory's own prefix as the module for these.
     *
     * @param list<string> $two_field_lines e.g. "greeting#:#Hallo, neu"
     */
    private function writePrefixedLangFile(
        string $root,
        LanguageFileDirectory $directory,
        string $lang_key,
        array $two_field_lines
    ): void {
        $dir = rtrim($root, '/') . '/' . ltrim($directory->getPath(), '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $dir . 'ilias_' . $lang_key . '.lang',
            "<!-- language file start -->\n" . implode("\n", $two_field_lines) . "\n"
        );
    }

    /**
     * Writes a customizing/local *.lang.local source file - three-field lines
     * (module#:#identifier#:#value), matching CustomizingLanguageFileDirectory's empty prefix.
     *
     * @param list<string> $three_field_lines e.g. "pilot#:#greeting#:#Hallo, neu"
     */
    private function writeCustomizingLangFile(string $root, string $lang_key, array $three_field_lines): void
    {
        $dir = rtrim($root, '/') . '/lang/customizing/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $dir . 'ilias_' . $lang_key . '.lang.local',
            "<!-- language file start -->\n" . implode("\n", $three_field_lines) . "\n"
        );
    }

    /**
     * insertLanguage() only logs (error_log()) and swallows a file-sync failure - it must never let a
     * PHP-level warning from the underlying file write (e.g. Generator::generateFile()'s
     * file_put_contents() call against a read-only target) escape as a PHPUnit-converted warning
     * failure, exactly like the caller itself does not let it escape as an exception. The
     * error_log() call itself is also redirected to a throwaway file for the duration of the call -
     * insertLanguage() has no injected logger and deliberately falls back to error_log() (see its
     * docblock), which would otherwise write to this process' stderr and trip
     * beStrictAboutOutputDuringTests.
     */
    private function withWarningsSuppressed(callable $callback): mixed
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        $previous_error_log = ini_set('error_log', sys_get_temp_dir() . '/ilias_lang_test_error_log_' . getmypid());
        try {
            return $callback();
        } finally {
            restore_error_handler();
            ini_set('error_log', $previous_error_log);
        }
    }

    /**
     * Regression coverage for the PO/MO write-back mirroring added to insertLanguage() (see its
     * docblock and tools/po-migration/README.md, "Bekannte Grenzen"): a new/changed value read from a
     * migrated module's *.lang file during installation must also land in that module's .po/.mo files,
     * not just in lng_modules - otherwise ilLanguage::txt() (which prefers the migrated file) would
     * never show it.
     */
    public function testInsertLanguageForInstallationSyncsNewLangFileValueIntoMigratedPoAndMoFiles(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', ['greeting' => 'Hallo']);
        $this->writePrefixedLangFile($root, $directory, 'de', ['greeting#:#Hallo, neu']);

        try {
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de');

            $translation = $this->loadFixturePo('pilot', 'de')->find('pilot', 'greeting');
            $this->assertNotNull($translation);
            $this->assertSame('Hallo, neu', $translation->getTranslation());
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * "Replace" semantics: an entry that used to be in the .po file but is no longer produced by the
     * freshly-read *.lang file must be removed, not just left stale - exactly like
     * ilObjLanguage::replaceLangModule() already does (see PoMigrationWriteBackTest).
     */
    public function testInsertLanguageForInstallationRemovesPoEntryNoLongerPresentInLangFile(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', [
            'greeting' => 'Hallo',
            'farewell' => 'Tschüss',
        ]);
        // The freshly-read *.lang file only produces "greeting" this time.
        $this->writePrefixedLangFile($root, $directory, 'de', ['greeting#:#Hallo']);

        try {
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de');

            $translations = $this->loadFixturePo('pilot', 'de');
            $this->assertNull($translations->find('pilot', 'farewell'));
            $this->assertNotNull($translations->find('pilot', 'greeting'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * A module present in the freshly-read *.lang data (and therefore written to lng_modules) but
     * never contributed as a LanguageFileDirectory must not cause any error and must not create any
     * file - the vast majority of modules are not part of the PO/MO pilot.
     */
    public function testInsertLanguageDoesNotErrorForModuleWithoutContributedDirectory(): void
    {
        $root = $this->createTempInstallationRoot();
        $this->writeLangFile($root . '/lang/ilias_de.lang', [['common', 'hello', 'Welt']]);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('common')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory()),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de');

            // The DB write for the (non-migrated) module still happened despite going through the
            // per-module sync loop.
            $inserts = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_modules')
            ));
            $this->assertCount(1, $inserts);
            $this->assertStringContainsString("'common'", $inserts[0]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * insertLanguageForInstallation()'s default ($create_missing_mo = false) is what UpdateLanguage
     * uses to refresh an already-installed language: a contributed module that has no compiled .mo
     * file yet must stay a no-op, exactly matching MigratedLanguageFileSync's own default contract
     * (see its unit tests) - only an explicit install (see the test below) may bootstrap it.
     */
    public function testInsertLanguageDoesNotSyncContributedModuleWithoutCompiledMoFile(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', ['greeting' => 'Hallo']);
        unlink($this->fixture_directory . '/pilot_de.mo');
        $this->writePrefixedLangFile($root, $directory, 'de', ['greeting#:#Hallo, neu']);

        try {
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de');

            $this->assertFileDoesNotExist($this->fixture_directory . '/pilot_de.mo');
            $this->assertSame(
                'Hallo',
                $this->loadFixturePo('pilot', 'de')->find('pilot', 'greeting')->getTranslation()
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * The counterpart to the test above: InstallLanguage (a genuine, first-time install - see
     * ILIAS\Language\Activities\InstallLanguage::perform()) passes $create_missing_mo = true, which
     * must compile a still-missing .mo from the module's shipped .po - this is the only place that
     * ever creates a migrated module's .mo file now that convert_module_to_po.php no longer does (see
     * tools/po-migration/README.md).
     */
    public function testInsertLanguageForInstallationCreatesMissingMoFileWhenBootstrapping(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', ['greeting' => 'Hallo']);
        unlink($this->fixture_directory . '/pilot_de.mo');
        $this->writePrefixedLangFile($root, $directory, 'de', ['greeting#:#Hallo, neu']);

        try {
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForInstallation('de', true);

            $this->assertFileExists($this->fixture_directory . '/pilot_de.mo');
            $this->assertSame(
                'Hallo, neu',
                $this->loadFixturePo('pilot', 'de')->find('pilot', 'greeting')->getTranslation()
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * insertLanguageForRemovingLocalChanges() syncs migrated files too, deliberately (see
     * insertLanguage()'s and this method's own docblocks): $lang_array here is exactly the shipped
     * global/component content, so this is precisely what "remove local changes" needs for a
     * migrated module - not just the DB side. This also covers a case the caller's own follow-up
     * step, ilObjLanguage::removeLocalChanges()'s resetMigratedLocalChanges(), cannot reach on its
     * own: an entry with no "original" comment (e.g. one added after migration via "add new
     * variable") - resetMigratedLocalChanges() skips those by design, so without this sync they would
     * survive a "remove local changes" pass forever in the .po/.mo file even though their DB row is
     * gone.
     */
    public function testInsertLanguageForRemovingLocalChangesSyncsMigratedFiles(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', ['greeting' => 'Hallo']);
        $this->writePrefixedLangFile($root, $directory, 'de', ['greeting#:#Hallo, geändert']);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForRemovingLocalChanges('de');

            // The DB write goes through with the freshly-read value...
            $inserts = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_modules')
            ));
            $this->assertCount(1, $inserts);
            $this->assertStringContainsString('Hallo, ge', $inserts[0]);

            // ...and the .po file is mirrored to match it.
            $translation = $this->loadFixturePo('pilot', 'de')->find('pilot', 'greeting');
            $this->assertNotNull($translation);
            $this->assertSame('Hallo, geändert', $translation->getTranslation());
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * The scenario resetMigratedLocalChanges() alone cannot fix: an entry added after migration (no
     * "original" comment at all) has no DB row once "remove local changes" flushes+reinstalls from
     * the shipped .lang content alone - insertLanguageForRemovingLocalChanges()'s sync must remove it
     * from the .po file too, or ilLanguage::txt() would keep serving it forever even though every DB
     * view of this language has no trace of it left.
     */
    public function testInsertLanguageForRemovingLocalChangesRemovesAnEntryThatOnlyExistsInTheFile(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', [
            'greeting' => 'Hallo',
            'custom_hint' => 'Nur in der Datei, nie ausgeliefert',
        ]);
        $this->writePrefixedLangFile($root, $directory, 'de', ['greeting#:#Hallo']);

        try {
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");

            $repository = $this->createStub(InstalledLanguageRepository::class);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForRemovingLocalChanges('de');

            $translations = $this->loadFixturePo('pilot', 'de');
            $this->assertNotNull($translations->find('pilot', 'greeting'));
            $this->assertNull($translations->find('pilot', 'custom_hint'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * insertLanguageForApplyingLocalChanges() ("Install local" on an already-installed language) syncs
     * migrated files just like insertLanguageForInstallation() does (its $sync_migrated_files default
     * is true too) - reading a *.lang.local override for a migrated module must update that module's
     * .po/.mo files as well.
     */
    public function testInsertLanguageForApplyingLocalChangesSyncsMigratedFiles(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', ['greeting' => 'Hallo, alt']);
        $this->writeCustomizingLangFile($root, 'de', ['pilot#:#greeting#:#Hallo, neu']);

        try {
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);
            $repository->method('getLanguageEntries')->willReturn(['pilot' => ['greeting' => 'Hallo, alt']]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForApplyingLocalChanges('de');

            $translation = $this->loadFixturePo('pilot', 'de')->find('pilot', 'greeting');
            $this->assertNotNull($translation);
            $this->assertSame('Hallo, neu', $translation->getTranslation());
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * $create_missing_mo = true on insertLanguageForApplyingLocalChanges() is what InstallLanguage
     * passes for mode "install_local" (still an install action, see MigratedLanguageFileSync::sync()'s
     * docblock) - a still-missing .mo for a migrated module must be bootstrapped here too, not just via
     * insertLanguageForInstallation().
     */
    public function testInsertLanguageForApplyingLocalChangesCreatesMissingMoFileWhenBootstrapping(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', ['greeting' => 'Hallo, alt']);
        unlink($this->fixture_directory . '/pilot_de.mo');
        $this->writeCustomizingLangFile($root, 'de', ['pilot#:#greeting#:#Hallo, neu']);

        try {
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);
            $repository->method('getLanguageEntries')->willReturn(['pilot' => ['greeting' => 'Hallo, alt']]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $manager->insertLanguageForApplyingLocalChanges('de', true);

            $this->assertFileExists($this->fixture_directory . '/pilot_de.mo');
            $translation = $this->loadFixturePo('pilot', 'de')->find('pilot', 'greeting');
            $this->assertNotNull($translation);
            $this->assertSame('Hallo, neu', $translation->getTranslation());
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * A failure writing a migrated module's .po/.mo files (e.g. a read-only lang/ directory in
     * production) must not abort or roll back the lng_data/lng_modules DB write that already
     * succeeded - insertLanguage() catches and error_log()s per-module sync failures individually (see
     * its docblock/implementation) for exactly this reason.
     */
    public function testInsertLanguageStillWritesToDatabaseWhenFileSyncFails(): void
    {
        $root = $this->createTempInstallationRoot();
        $directory = $this->seedMigratedFixtureModule($root, 'pilot', 'de', ['greeting' => 'Hallo']);
        chmod($this->fixture_directory . '/pilot_de.po', 0444);
        $this->writePrefixedLangFile($root, $directory, 'de', ['greeting#:#Hallo, neu']);

        try {
            $calls = [];
            $db = $this->createDatabaseMock();
            $db->method('in')->willReturn("module IN ('pilot')");
            $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
                $calls[] = $query;
                return 1;
            });

            $repository = $this->createStub(InstalledLanguageRepository::class);
            $repository->method('getLocalChanges')->willReturn([]);

            $manager = new LanguageInstallationManager(
                $db,
                new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory(), $directory),
                $root,
                $repository
            );

            $this->withWarningsSuppressed(static function () use ($manager): void {
                $manager->insertLanguageForInstallation('de');
            });

            $inserts = array_values(array_filter(
                $calls,
                static fn(string $query): bool => str_starts_with($query, /** @lang text */ 'INSERT INTO lng_modules')
            ));
            $this->assertCount(1, $inserts, 'The lng_modules write must happen despite the file-sync failure.');
            $this->assertStringContainsString("'pilot'", $inserts[0]);

            // The read-only file itself was of course never actually updated.
            $this->assertSame(
                'Hallo',
                $this->loadFixturePo('pilot', 'de')->find('pilot', 'greeting')->getTranslation()
            );
        } finally {
            chmod($this->fixture_directory . '/pilot_de.po', 0664);
            $this->removeDirectory($root);
        }
    }

    private function createPrefixedComponentDirectory(string $path, string $prefix): LanguageFileDirectory
    {
        return new class ($path, $prefix) implements LanguageFileDirectory {
            public function __construct(private string $path, private string $prefix)
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

    private function createTempInstallationRoot(): string
    {
        $dir = sys_get_temp_dir() . '/ilias_lang_test_' . bin2hex(random_bytes(8));
        mkdir($dir . '/lang/customizing', 0777, true);
        return $dir;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $entries module, identifier, value
     */
    private function writeLangFile(string $path, array $entries): void
    {
        $lines = ["<!-- language file start -->"];
        foreach ($entries as [$module, $identifier, $value]) {
            $lines[] = "{$module}#:#{$identifier}#:#{$value}";
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
