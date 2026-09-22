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
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage for MigratedLanguageFileSync::sync() itself, independent of any of its three
 * callers (ilObjLanguage::replaceLangModule() - see PoMigrationWriteBackTest.php -,
 * ilObjLanguageExt::importLanguageFile(), ILIAS\Language\Setup\LanguageInstallationManager). Those
 * caller-level suites already exercise sync() end-to-end for their own scenarios; this file targets
 * behavior of sync() itself that either has no natural caller-level trigger (the "$entries = []"
 * full-wipe semantics; the getContext() guard on which stale entries get removed) or is more precisely
 * pinned when asserted directly against the thrown exception rather than through a caller's
 * catch-and-swallow wrapper.
 *
 * Uses a throwaway fixture module, exactly like PoMigrationWriteBackTest.php - never real pilot
 * (tos) data.
 */
class MigratedLanguageFileSyncTest extends TestCase
{
    private ?string $fixture_directory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../../'));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('chmod', glob($this->fixture_directory . '/*') ?: [], array_fill(0, count(glob($this->fixture_directory . '/*') ?: []), 0664));
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, array{value: string, context?: string}> $entries identifier => details;
     *        'context' defaults to $module - pass a different value to seed a translation belonging to
     *        a different module's context inside the same fixture file (see
     *        testLeavesTranslationsOfADifferentContextAloneWhenWipingAllEntries()).
     */
    private function seedFixtureModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-sync-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $entry) {
            $translation = Translation::create($entry['context'] ?? $module, $identifier)
                ->translate($entry['value']);
            $translations->add($translation);
        }

        $base_path = $this->fixture_directory . '/' . $module . '_' . $lang_key;
        (new PoGenerator())->generateFile($translations, $base_path . '.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $base_path . '.mo');

        $relative_path = 'components/ILIAS/Language/tests/ComponentTranslation/'
            . basename($this->fixture_directory) . '/';

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

    private function loadFixturePo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po');
    }

    public function testSyncingWithEmptyEntriesRemovesEveryExistingEntryForThatModule(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', []);

        $translations = $this->loadFixturePo('stest', 'de');
        $this->assertNull($translations->find('stest', 'greeting'));
        $this->assertNull($translations->find('stest', 'farewell'));
        $this->assertCount(0, iterator_to_array($translations->getTranslations()));
    }

    /**
     * The stale-detection only removes translations whose getContext() equals the module being
     * synced (see sync()'s docblock/implementation) - a translation that happens to live in the same
     * .po file under a different context must survive an "$entries = []" full wipe untouched. This
     * pins that guard against a mutation that drops the getContext() check (which would wipe the
     * entire file's contents regardless of context).
     */
    public function testLeavesTranslationsOfADifferentContextAloneWhenWipingAllEntries(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'unrelated' => ['value' => 'Bleibt', 'context' => 'other_module'],
        ]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', []);

        $translations = $this->loadFixturePo('stest', 'de');
        $this->assertNull($translations->find('stest', 'greeting'));
        $survivor = $translations->find('other_module', 'unrelated');
        $this->assertNotNull($survivor);
        $this->assertSame('Bleibt', $survivor->getTranslation());
    }

    public function testIsANoOpWhenTheModuleHasNoContributedDirectory(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // "other_module" is not contributed by anyone -> must not throw, must not create any file
        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'other_module', ['x' => 'y']);

        $this->assertFileDoesNotExist($this->fixture_directory . '/other_module_de.po');
    }

    public function testIsANoOpWhenNoCompiledMoFileExistsYet(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo, geändert']);

        $this->assertFileDoesNotExist($this->fixture_directory . '/stest_de.mo');
        $this->assertSame(
            'Hallo',
            $this->loadFixturePo('stest', 'de')->find('stest', 'greeting')->getTranslation()
        );
    }

    /**
     * $create_missing_mo = true (only ever passed by LanguageInstallationManager for a genuine
     * install, see its docblock) is the one case allowed to compile a `.mo` that doesn't exist yet -
     * the counterpart to testIsANoOpWhenNoCompiledMoFileExistsYet() above, which pins the default
     * (update/edit) behavior of leaving it missing.
     */
    public function testCreatesAMissingMoFileWhenCreateMissingMoIsTrue(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo, neu'],
            true
        );

        $this->assertFileExists($this->fixture_directory . '/stest_de.mo');
        $this->assertSame(
            'Hallo, neu',
            $this->loadFixturePo('stest', 'de')->find('stest', 'greeting')->getTranslation()
        );
    }

    /**
     * $create_missing_mo = true still requires a `.po` file to exist - it bootstraps the compiled
     * `.mo` from the shipped `.po`, it does not fabricate translations out of nothing.
     */
    public function testStillANoOpWithCreateMissingMoTrueWhenNoPoFileExistsEither(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->fixture_directory . '/stest_de.po');
        unlink($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo'], true);

        $this->assertFileDoesNotExist($this->fixture_directory . '/stest_de.po');
        $this->assertFileDoesNotExist($this->fixture_directory . '/stest_de.mo');
    }

    /**
     * A bootstrap install must compile the `.mo` even when the `.po` content itself would otherwise be
     * byte-identical (the no-op guard that skips rewriting an unchanged file must not also skip
     * creating the still-missing `.mo` - see sync()'s docblock on $mo_is_missing).
     */
    public function testCreatesAMissingMoFileEvenWhenPoContentIsAlreadyUpToDate(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // Same value as already seeded - the .po content will not change.
        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo'], true);

        $this->assertFileExists($this->fixture_directory . '/stest_de.mo');
    }

    /**
     * Per its own docblock, sync() always throws instead of logging/swallowing - every caller decides
     * that for itself. Pinned directly here rather than only observed indirectly through a caller's
     * catch block.
     */
    public function testThrowsWhenThePoFileCannotBeWritten(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        chmod($this->fixture_directory . '/stest_de.po', 0444);

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/PO file/');
            MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo, geändert']);
        } finally {
            restore_error_handler();
            chmod($this->fixture_directory . '/stest_de.po', 0664);
        }
    }

    /**
     * removeMoFile() is the uninstall counterpart to sync()'s $create_missing_mo (see its docblock) -
     * it must remove a module's compiled .mo for the given language while leaving the .po untouched,
     * the .po being the shipped source of truth that a later re-install compiles a fresh .mo from.
     */
    public function testRemoveMoFileDeletesTheMoFileButLeavesThePoFileUntouched(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );
        $po_before = file_get_contents($this->fixture_directory . '/stest_de.po');

        MigratedLanguageFileSync::removeMoFile($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest');

        $this->assertFileDoesNotExist($this->fixture_directory . '/stest_de.mo');
        $this->assertSame($po_before, file_get_contents($this->fixture_directory . '/stest_de.po'));
    }

    public function testRemoveMoFileIsANoOpWhenNoMoFileExistsYet(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->fixture_directory . '/stest_de.mo');
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // must not throw even though there is nothing to remove
        MigratedLanguageFileSync::removeMoFile($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest');

        $this->assertFileExists($this->fixture_directory . '/stest_de.po');
    }

    public function testRemoveMoFileIsANoOpWhenTheModuleHasNoContributedDirectory(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // "other_module" is not contributed by anyone -> must not throw, must not touch stest's files
        MigratedLanguageFileSync::removeMoFile($manager, ILIAS_ABSOLUTE_PATH, 'de', 'other_module');

        $this->assertFileExists($this->fixture_directory . '/stest_de.mo');
    }

    /**
     * Per its own docblock, removeMoFile() always throws instead of logging/swallowing - every caller
     * decides that for itself, exactly like sync().
     */
    public function testRemoveMoFileThrowsWhenTheMoFileCannotBeRemoved(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot force an unremovable file while running as root; skipping.');
        }

        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );
        // unlink() fails on a directory permission, not a file permission, and emits a PHP warning
        // before returning false - silenced the same way testThrowsWhenThePoFileCannotBeWritten() does.
        chmod($this->fixture_directory, 0555);

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/MO file/');
            MigratedLanguageFileSync::removeMoFile($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest');
        } finally {
            restore_error_handler();
            chmod($this->fixture_directory, 0775);
        }
    }
}
