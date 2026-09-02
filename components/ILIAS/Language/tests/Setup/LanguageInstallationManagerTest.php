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
    private function createDatabaseMock(): ilDBInterface
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
                $this->stringContains('UPDATE object_data'),
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
                $this->stringContains('INSERT INTO object_data'),
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
            static fn(string $query): bool => str_contains($query, 'UPDATE object_data')
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
                $this->stringContains('DELETE FROM lng_data'),
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
        $this->assertStringContainsString('DELETE FROM lng_data', $calls[0]);
        $this->assertStringNotContainsString('local_change IS NULL', $calls[0]);
        $this->assertStringContainsString('DELETE FROM lng_modules', $calls[1]);
    }
}
