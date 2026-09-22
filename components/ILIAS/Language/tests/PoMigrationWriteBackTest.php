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
use ILIAS\Language\ComponentTranslation\LocalChangeComments;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;

/**
 * Covers the PO/MO pilot's write-back path (see components/ILIAS/Language/tools/po-migration/README.md):
 * ilObjLanguage::replaceLangModule() - the primitive every language-admin write (GUI edit, add new
 * variable, delete, plugin-language update) funnels through to rewrite a module's lng_modules row -
 * now also mirrors a migrated module's .po/.mo files, without ever skipping the lng_modules write
 * itself (dual-write, so the DB stays a valid rollback target).
 *
 * As of the overlay split (see README, "Overlay: Installations-eigene `.po`/`.mo`-Dateien"), the
 * git-tracked SHIPPED `.po`/`.mo` pair (rooted under ILIAS_ABSOLUTE_PATH, exactly where
 * convert_module_to_po.php would have written it) is never written to again by replaceLangModule() -
 * every actual write goes to a separate, per-installation OVERLAY `.po`/`.mo` pair rooted under
 * CLIENT_DATA_DIR instead (see MigratedLanguageFileSyncTest.php for the same two-root fixture
 * pattern, applied there directly against MigratedLanguageFileSync itself rather than through this
 * class' caller). Every test below therefore seeds the SHIPPED pair, bootstraps the OVERLAY pair from
 * it (simulating a module whose language was already installed - sync()'s default $create_missing_mo
 * = false, exactly what replaceLangModule() passes, never creates a still-missing overlay .mo), reads
 * its assertions from the OVERLAY, and additionally verifies the SHIPPED pair stayed byte-identical
 * throughout (the git-dirtying bug this split exists to prevent).
 *
 * Uses a throwaway fixture module (not tos's real files) so these tests never touch real pilot data.
 */
class PoMigrationWriteBackTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
        // MigratedLanguageFileSync's overlay location (see this class' own docblock, and
        // tools/po-migration/README.md, "Overlay") is rooted at the CLIENT_DATA_DIR constant, never
        // at ILIAS_ABSOLUTE_PATH. A PHP constant cannot be redefined, so this is guarded exactly like
        // ILIAS_ABSOLUTE_PATH above - see PoMigrationLoadLanguageModuleTest.php for the same pattern.
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_lang_test_client_data_dir');
        }

        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_translations_cache')->setValue(null, []);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }

        $overlay_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . ($this->fixture_directory !== null ? basename($this->fixture_directory) : '');
        if (isset($this->fixture_directory) && is_dir($overlay_dir)) {
            array_map('unlink', glob($overlay_dir . '/*') ?: []);
            rmdir($overlay_dir);
        }

        parent::tearDown();
    }

    /**
     * Seeds a real .po/.mo pair for a throwaway module, exactly like the real conversion tool would,
     * at the SHIPPED (ILIAS_ABSOLUTE_PATH-rooted) location - and returns the LanguageFileDirectory
     * that makes it discoverable the same way a real ComponentLanguageFileDirectory contribution
     * would. Never creates an overlay by itself - see bootstrapOverlayFromShipped() below.
     *
     * @param array<string, array{value: string, fuzzy?: bool, original?: string}> $entries 'original',
     *   when given, seeds the entry exactly as convert_module_to_po.php would (see LocalChangeComments)
     *   - omit it to simulate a key that never went through the conversion tool.
     */
    private function seedFixtureModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-writeback-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $entry) {
            $translation = Translation::create($module, $identifier)->translate($entry['value']);
            if ($entry['fuzzy'] ?? false) {
                $translation->getFlags()->add('fuzzy');
            }
            if (isset($entry['original'])) {
                LocalChangeComments::setOriginal($translation, $entry['original']);
            }
            $translations->add($translation);
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

    /**
     * Copies the current shipped `.po`/`.mo` pair for $module/$lang_key into the overlay location,
     * simulating a module whose language was already installed by an earlier run - the normal starting
     * point for every write-back test here, since replaceLangModule() always passes sync()'s default
     * $create_missing_mo = false (see MigratedLanguageFileSync::sync()'s docblock): it only ever
     * refreshes an overlay that already exists, never bootstraps a still-missing one.
     */
    private function bootstrapOverlayFromShipped(string $module, string $lang_key): void
    {
        $overlay_base = $this->overlayBase($module, $lang_key);
        if (!is_dir(dirname($overlay_base))) {
            mkdir(dirname($overlay_base), 0775, true);
        }
        copy($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po', $overlay_base . '.po');
        copy($this->fixture_directory . '/' . $module . '_' . $lang_key . '.mo', $overlay_base . '.mo');
    }

    private function overlayBase(string $module, string $lang_key): string
    {
        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . basename((string) $this->fixture_directory) . '/' . $module . '_' . $lang_key;
    }

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    /**
     * replaceLangModule() unserializes what it just wrote as a self-check (mantis #20046/#19140)
     * before returning; the DB itself is a stub here (this suite is about the file mirror, not the
     * DB write, which is untouched pre-existing behavior), so the stub only needs to make that
     * self-check pass - its content doesn't matter, just that it decodes to an array.
     */
    private function stubDatabaseForReplaceLangModule(): void
    {
        $statement = $this->createStub(ilDBStatement::class);
        $db = $this->createStub(ilDBInterface::class);
        $db->method('queryF')->willReturn($statement);
        $db->method('fetchAssoc')->willReturn(['lang_array' => serialize([])]);
        $this->setGlobalVariable('ilDB', $db);
    }

    private function loadFixturePo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po');
    }

    private function loadOverlayPo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->overlayBase($module, $lang_key) . '.po');
    }

    public function testUpdatesAnExistingEntryAndClearsItsFuzzyFlag(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo', 'fuzzy' => true],
        ]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);

        $translation = $this->loadOverlayPo('wtest', 'de')->find('wtest', 'greeting');
        $this->assertNotNull($translation);
        $this->assertSame('Hallo, geändert', $translation->getTranslation());
        $this->assertFalse($translation->getFlags()->has('fuzzy'));

        // the shipped file must never be touched by an ordinary admin edit, no matter what
        $shipped = $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting');
        $this->assertSame('Hallo', $shipped->getTranslation());
        $this->assertTrue($shipped->getFlags()->has('fuzzy'));
    }

    public function testAddsANewEntryThatDidNotExistInTheFileBefore(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo'],
        ]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', [
            'greeting' => 'Hallo',
            'farewell' => 'Tschüss',
        ]);

        $translation = $this->loadOverlayPo('wtest', 'de')->find('wtest', 'farewell');
        $this->assertNotNull($translation);
        $this->assertSame('Tschüss', $translation->getTranslation());

        // never added to the shipped file
        $this->assertNull($this->loadFixturePo('wtest', 'de')->find('wtest', 'farewell'));
    }

    public function testRemovesAnEntryThatIsNoLongerInTheReplacedArray(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);

        // "farewell" is deleted server-side before replaceLangModule() rebuilds the row from what
        // remains - exactly what _deleteValues() does (see class.ilObjLanguageExt.php)
        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo']);

        $this->assertNull($this->loadOverlayPo('wtest', 'de')->find('wtest', 'farewell'));
        $this->assertNotNull($this->loadOverlayPo('wtest', 'de')->find('wtest', 'greeting'));

        // the shipped file keeps both entries - it is never touched
        $this->assertNotNull($this->loadFixturePo('wtest', 'de')->find('wtest', 'farewell'));
        $this->assertNotNull($this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting'));
    }

    /**
     * The pilot's "roll back one language" lever is removing just the overlay's .mo file (see README,
     * "Rollback") - the overlay .po (and the shipped pair entirely) stay. If the write path only
     * checked for the .po, the very next admin edit would silently regenerate the .mo and undo that
     * rollback. Gating on the .mo file too (matching exactly what ilLanguage's read path checks)
     * prevents that.
     */
    public function testIsANoOpWhenOnlyTheMoFileWasRemovedAsAPerLanguageRollback(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);
        unlink($this->overlayBase('wtest', 'de') . '.mo');

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);

        $this->assertFileDoesNotExist($this->overlayBase('wtest', 'de') . '.mo');
        $this->assertSame(
            'Hallo',
            $this->loadOverlayPo('wtest', 'de')->find('wtest', 'greeting')->getTranslation()
        );
        // shipped pair untouched throughout
        $this->assertFileExists($this->fixture_directory . '/wtest_de.mo');
        $this->assertSame(
            'Hallo',
            $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting')->getTranslation()
        );
    }

    public function testIsANoOpWhenTheModuleHasNoContributedDirectory(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->registerDirectoryManager($directory);

        // "other_module" is not contributed by anyone -> must not touch any file, must not throw
        ilObjLanguage::replaceLangModule('de', 'other_module', ['x' => 'y']);

        $this->assertSame(
            'Hallo',
            $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting')->getTranslation()
        );
        $this->assertFileDoesNotExist($this->fixture_directory . '/other_module_de.po');
        $this->assertDirectoryDoesNotExist(rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . basename($this->fixture_directory) . '/other_module_de.po');
    }

    public function testIsANoOpWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        $this->stubDatabaseForReplaceLangModule();

        // must not throw even though nothing was contributed and no fixture file exists anywhere
        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo']);

        $this->addToAssertionCount(1);
    }

    /**
     * The whole point of invalidateMigratedLanguageFileCache(): without it, ilLanguage would keep
     * serving the value it cached before this write for the rest of the request. ilLanguage's read
     * path (ilLanguage::migratedOverlayMoFile()) reads exclusively from the CLIENT_DATA_DIR-rooted
     * overlay - never the shipped file - so the overlay must already be bootstrapped for the "before"
     * read to see anything at all.
     */
    public function testInvalidatesIlLanguagesCacheSoTheNewValueIsVisibleImmediately(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);

        $before = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        (new ReflectionObject($before))->getProperty('lang_key')->setValue($before, 'de');
        $before->loadLanguageModule('wtest');
        $this->assertSame('Hallo', $before->txt('greeting'));

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);

        $after = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        (new ReflectionObject($after))->getProperty('lang_key')->setValue($after, 'de');
        $after->loadLanguageModule('wtest');
        $this->assertSame('Hallo, geändert', $after->txt('greeting'));
    }

    /**
     * End-to-end coverage of LocalChangeComments (see its own unit tests in
     * components/ILIAS/Language/tests/ComponentTranslation/LocalChangeCommentsTest.php) through the
     * actual write path an admin edit funnels through: editing an entry away from the value the
     * conversion tool shipped it with ("original") must leave a local_change timestamp behind - in the
     * overlay, since that is the only file this write path ever touches.
     */
    public function testWritingADifferentValueThanTheOriginalSetsALocalChangeTimestamp(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
        ]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);

        $translation = $this->loadOverlayPo('wtest', 'de')->find('wtest', 'greeting');
        $this->assertNotNull($translation);
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($translation));
        $this->assertNotNull(LocalChangeComments::getLocalChange($translation));

        // the shipped "original" baseline is never touched by this or any other write
        $this->assertSame(
            'Hallo',
            $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting')->getTranslation()
        );
    }

    /**
     * The flip side: writing the value back to what the conversion tool originally shipped clears the
     * local_change marker again - the pilot's equivalent of the DB-backed scheme's "reset to default"
     * (see README, "Schreibpfad"), without a dedicated reset action.
     */
    public function testWritingBackTheOriginalValueClearsAnExistingLocalChangeTimestamp(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo'],
        ]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);

        // sanity check: the fixture starts out already locally changed
        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);
        $this->assertNotNull(LocalChangeComments::getLocalChange(
            $this->loadOverlayPo('wtest', 'de')->find('wtest', 'greeting')
        ));

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo']);

        $translation = $this->loadOverlayPo('wtest', 'de')->find('wtest', 'greeting');
        $this->assertSame('Hallo', $translation->getTranslation());
        $this->assertNull(LocalChangeComments::getLocalChange($translation));
    }

    /**
     * A key with no "original" comment at all - never seeded by the conversion tool, e.g. added by an
     * admin after migration - always counts as locally changed (see
     * LocalChangeComments::refresh()'s docblock).
     */
    public function testANewEntryWithNoOriginalCommentIsAlwaysMarkedAsLocallyChanged(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
        ]);
        $this->bootstrapOverlayFromShipped('wtest', 'de');
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', [
            'greeting' => 'Hallo',
            'farewell' => 'Tschüss',
        ]);

        $translation = $this->loadOverlayPo('wtest', 'de')->find('wtest', 'farewell');
        $this->assertNotNull($translation);
        $this->assertNull(LocalChangeComments::getOriginal($translation));
        $this->assertNotNull(LocalChangeComments::getLocalChange($translation));
    }
}
