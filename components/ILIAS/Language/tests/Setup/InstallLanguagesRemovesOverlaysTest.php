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

use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Language\Setup\LanguageInstallationManager;
use PHPUnit\Framework\TestCase;

/**
 * T5 (M6): installLanguages() must remove the on-disk PO/MO overlay of a
 * module migrated to the PO/MO pilot for every language that WAS previously
 * known but is no longer among the requested $lang_keys (i.e. it is being
 * uninstalled/deselected as a side effect of this call) - see
 * LanguageInstallationManager::removeOverlays(), called from the
 * "no longer requested" branch of installLanguages()' second loop. Without
 * this, the database bookkeeping flips to "not_installed" but the migrated
 * module's compiled overlay stays on disk and keeps being served (see that
 * method's own docblock, and ilObjLanguage::uninstall()'s analogous
 * removeMigratedMoFiles() for the single-language counterpart).
 *
 * Uses the same fixture technique as LanguageInstallationManagerMigratedModulesTest:
 * a LanguageFileDirectory rooted at a throwaway temp directory (never
 * ILIAS_ABSOLUTE_PATH), so nothing is ever written outside sys_get_temp_dir().
 */
class InstallLanguagesRemovesOverlaysTest extends TestCase
{
    private const string MODULE = 'pilot';

    private string $root;
    private LanguageFileDirectory $directory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ilias_lim_overlay_test_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/lang/customizing', 0775, true);
        mkdir($this->root . '/client-data', 0775, true);
        $this->directory = MigratedPoFixture::directory(self::MODULE, 'components/pilot/lang/');
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->root);
    }

    private function overlayBase(string $lang_key): string
    {
        return $this->root . '/client-data/lang/components/pilot/lang/pilot_' . $lang_key;
    }

    private function seedOverlay(string $lang_key): void
    {
        MigratedPoFixture::writePair(
            $this->overlayBase($lang_key),
            MigratedPoFixture::catalog(self::MODULE, ['greeting' => 'Hallo'])
        );
    }

    private function createDatabaseStub(): ilDBInterface
    {
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            static fn(mixed $value): string => "'" . (string) $value . "'"
        );
        $db->method('now')->willReturn('NOW()');
        $db->method('manipulate')->willReturn(1);

        return $db;
    }

    private function createManager(InstalledLanguageRepository $repository): LanguageInstallationManager
    {
        return new LanguageInstallationManager(
            $this->createDatabaseStub(),
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                new MainLanguageFileDirectory(),
                $this->directory
            ),
            $this->root,
            $repository,
            null,
            fn(): string => $this->root . '/client-data'
        );
    }

    /**
     * Mutation coverage: dropping the `$this->removeOverlays((string) $key);`
     * call from installLanguages()' "no longer requested" branch (or an
     * off-by-one that only removes overlays for a STILL requested key)
     * would leave this overlay behind.
     */
    public function testDeselectingAPreviouslyInstalledLanguageRemovesItsOverlayFiles(): void
    {
        $this->seedOverlay('de');
        $this->assertFileExists($this->overlayBase('de') . '.po');
        $this->assertFileExists($this->overlayBase('de') . '.mo');

        $repository = $this->createStub(InstalledLanguageRepository::class);
        $repository->method('getAvailableLanguages')->willReturn([
            'de' => ['obj_id' => 3, 'status' => 'installed'],
        ]);
        $repository->method('getLocalLanguages')->willReturn([]);

        $manager = $this->createManager($repository);

        // 'de' is not part of the requested keys anymore - it must be
        // uninstalled, including its overlay.
        $result = $manager->installLanguages([]);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->overlayBase('de') . '.po');
        $this->assertFileDoesNotExist($this->overlayBase('de') . '.mo');
    }

    /**
     * Boundary/companion: a language that IS still among the requested keys
     * must keep its overlay untouched - pins that removeOverlays() is only
     * reached for the "no longer requested" branch, not unconditionally for
     * every known language.
     */
    public function testStillRequestedLanguageKeepsItsOverlayFiles(): void
    {
        $this->seedOverlay('de');

        $repository = $this->createStub(InstalledLanguageRepository::class);
        $repository->method('checkLanguage')->willReturn(true);
        $repository->method('getAvailableLanguages')->willReturn([
            'de' => ['obj_id' => 3, 'status' => 'installed'],
        ]);
        $repository->method('getLocalLanguages')->willReturn([]);
        $repository->method('getLocalChanges')->willReturn([]);

        $manager = $this->createManager($repository);

        $result = $manager->installLanguages(['de']);

        $this->assertTrue($result);
        $this->assertFileExists($this->overlayBase('de') . '.po');
        $this->assertFileExists($this->overlayBase('de') . '.mo');
    }
}
