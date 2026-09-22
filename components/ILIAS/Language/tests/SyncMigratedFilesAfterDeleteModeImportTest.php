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
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;

/**
 * Covers ilObjLanguageExt::syncMigratedFilesAfterDeleteModeImport() (see its docblock in
 * classes/class.ilObjLanguageExt.php), the fix for the second, previously undocumented PO/MO
 * write-back gap: importLanguageFile()'s "delete" mode wipes lng_data/lng_modules for the *whole*
 * language up front. That means _saveValues()'s own per-module loop
 * (class.ilObjLanguageExt.php::_saveValues()) finds no existing lng_modules row for *any* module -
 * migrated or not - and therefore never reaches replaceLangModule() (and, through it, the ordinary
 * PO/MO sync) for a single one of them. This method does not rely on _saveValues() having synced
 * anything: it independently syncs every module the language previously had any lng_data row for,
 * unioned with every module actually present in $to_save, each with exactly the entries $to_save just
 * wrote back for it (or an empty map for a module the imported file did not mention at all) -
 * "replace" semantics, matching every other write path. Without this method, a migrated module (see
 * tools/po-migration/README.md) would keep serving stale/orphaned values forever via ilLanguage's
 * migrated-file read path after a "delete"-mode import, regardless of whether the imported file
 * mentioned it or not.
 *
 * Deliberately tests this private method directly via reflection instead of driving the whole
 * importLanguageFile() end-to-end: importLanguageFile()'s "delete" mode routes through
 * ilObjLanguageExt::_saveValues(), which in turn requires a real global language file
 * (ilLanguageFile::_getGlobalLanguageFile()) and a long chain of further raw lng_data/lng_modules
 * queries entirely unrelated to this fix. Exercising all of that just to reach this method would
 * make the test suite fragile and hard to maintain without adding coverage of the actual new logic -
 * the reflection-based approach isolates exactly what changed. importLanguageFile()'s wiring itself
 * (capturing modules_before_delete via self::_getModules() before the delete, then calling this method
 * afterwards only for mode "delete") is a two-line change visible by inspection in
 * class.ilObjLanguageExt.php.
 *
 * Uses a throwaway fixture module, exactly like PoMigrationWriteBackTest.php - never real pilot (tos)
 * data.
 */
class SyncMigratedFilesAfterDeleteModeImportTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;

    /**
     * syncMigratedFilesAfterDeleteModeImport() evaluates ILIAS_ABSOLUTE_PATH as a plain function
     * argument to MigratedLanguageFileSync::sync() - unconditionally, before sync() itself gets a
     * chance to no-op for a module without a contributed directory. Every test method must therefore
     * be able to rely on the constant being defined, including one that never calls
     * seedFixtureModule() itself (e.g. a non-migrated-module scenario) - defining it here in setUp()
     * rather than lazily inside seedFixtureModule() avoids a test-order dependency on some other test
     * happening to run first in the same process (executionOrder="random" in phpunit.xml).
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $entries identifier => value
     */
    private function seedFixtureModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-clearmigrated-fixtures-' . bin2hex(random_bytes(4));
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

        $relative_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/';

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

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    private function loadFixturePo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po');
    }

    /**
     * @param array<string, string> $to_save module.separator.topic => value, exactly the shape
     *        importLanguageFile() passes on (via _saveValues()'s own $a_values parameter).
     */
    private function invoke(array $modules_before_delete, array $to_save): void
    {
        $instance = (new ReflectionClass(ilObjLanguageExt::class))->newInstanceWithoutConstructor();
        $instance->key = 'de';
        $instance->separator = '#:#';

        $method = new ReflectionMethod(ilObjLanguageExt::class, 'syncMigratedFilesAfterDeleteModeImport');
        $method->invoke($instance, $modules_before_delete, $to_save);
    }

    /**
     * A module that existed before the "delete"-mode wipe and has no entries at all in $to_save (the
     * imported file did not mention it) must be synced with an empty map - i.e. every one of its
     * entries removed from the .po/.mo files, matching "replace" semantics for a module that is now
     * genuinely gone.
     */
    public function testSyncsAModuleMissingFromTheImportWithAnEmptyMap(): void
    {
        $directory = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);

        // "dtest" existed before the "delete"-mode wipe, but the imported file contained nothing for
        // it at all.
        $this->invoke(['dtest'], []);

        $this->assertNull($this->loadFixturePo('dtest', 'de')->find('dtest', 'greeting'));
    }

    /**
     * The actual bugfix: a module that both existed before the delete AND is present in $to_save must
     * be synced with exactly its identifier => value subset from $to_save - not ignored (the old,
     * buggy assumption that _saveValues() already handled it via replaceLangModule(), which does not
     * hold in "delete" mode - see this class' and the method's own docblock) and not wiped empty
     * either.
     */
    public function testSyncsAModulePresentInTheImportWithItsToSaveSubset(): void
    {
        $directory = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);

        $this->invoke(['dtest'], ['dtest#:#greeting' => 'Hallo, neu']);

        $translation = $this->loadFixturePo('dtest', 'de')->find('dtest', 'greeting');
        $this->assertNotNull($translation);
        $this->assertSame('Hallo, neu', $translation->getTranslation());
    }

    public function testDoesNotThrowForANonMigratedModuleMissingFromTheImport(): void
    {
        $this->registerDirectoryManager();

        // "plain_module" never contributed a LanguageFileDirectory at all.
        $this->invoke(['plain_module'], []);

        $this->addToAssertionCount(1);
    }

    public function testIsANoOpWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        // Deliberately no registerDirectoryManager() call - $DIC->offsetExists() must be false.
        $this->invoke(['dtest'], []);

        $this->addToAssertionCount(1);
    }

    /**
     * Boundary case for the $to_save key parsing: explode($this->separator, $key) must yield exactly
     * two parts to be attributed to a module at all (see the method's `count($parts) === 2` check). A
     * key with no separator in it at all must not be misread as belonging to some module - this pins
     * that boundary against a mutation loosening it to e.g. `>= 1` or dropping the check, either of
     * which could wrongly attribute a bogus entry to a module or silently skip the union entirely.
     */
    public function testAToSaveKeyWithoutASeparatorDoesNotAccidentallyAttachToAnyModule(): void
    {
        $directory = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);

        // A malformed/unexpected key with no "#:#" in it at all - "dtest" is still only reached via
        // $modules_before_delete here, so it must be synced with an empty map, exactly as if $to_save
        // had been empty.
        $this->invoke(['dtest'], ['not_a_valid_key' => 'value']);

        $this->assertNull($this->loadFixturePo('dtest', 'de')->find('dtest', 'greeting'));
    }

    /**
     * Multiple modules existed before the delete; only one of them is present in $to_save. Each must
     * be synced with its own correct entries - the missing one emptied, the present one updated to its
     * $to_save subset - not all treated the same (e.g. an implementation that clears everything in
     * $modules_before_delete regardless of $to_save, or one that skips every module in $to_save
     * instead of syncing it).
     */
    public function testSyncsEachOfSeveralModulesWithItsOwnCorrectEntries(): void
    {
        $missing = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $kept = $this->seedFixtureModule('ktest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($missing, $kept);

        $this->invoke(['dtest', 'ktest'], ['ktest#:#greeting' => 'Hallo, neu']);

        $this->assertNull($this->loadFixturePo('dtest', 'de')->find('dtest', 'greeting'));
        $updated = $this->loadFixturePo('ktest', 'de')->find('ktest', 'greeting');
        $this->assertNotNull($updated);
        $this->assertSame('Hallo, neu', $updated->getTranslation());
    }
}
