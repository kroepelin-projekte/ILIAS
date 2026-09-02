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

use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Language\Setup\LanguageInstallationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for LanguageInstallationManager, in particular for the
 * "DATE field is not set correctly on changes of a language" concern
 * formerly flagged as a @todo on ilSetupLanguage - see its class docblock.
 * Since the manager accepts an injectable clock, these tests can assert the
 * exact last_update value instead of only that *some* value was set.
 */
class LanguageInstallationManagerTest extends TestCase
{
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
            $this->createMock(InstalledLanguageRepository::class),
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))
        );

        $manager->registerInstalledLanguage(
            $db,
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
            $this->createMock(InstalledLanguageRepository::class),
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'))
        );

        $manager->registerInstalledLanguage($db, 'de', [], []);
    }

    public function testInstallLanguagesSetsLastUpdateOnUninstallUsingInjectedClock(): void
    {
        $db = $this->createDatabaseMock();
        $calls = [];
        $db->method('manipulate')->willReturnCallback(static function (string $query) use (&$calls): int {
            $calls[] = $query;
            return 1;
        });

        $repository = $this->createMock(InstalledLanguageRepository::class);
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

        $manager = $this->createManager($db, $this->createMock(InstalledLanguageRepository::class));

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

        $manager = $this->createManager($db, $this->createMock(InstalledLanguageRepository::class));

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

            $repository = $this->createMock(InstalledLanguageRepository::class);
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
