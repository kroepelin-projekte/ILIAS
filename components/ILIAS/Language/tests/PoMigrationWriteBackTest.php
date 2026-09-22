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

        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_translations_cache')->setValue(null, []);
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
     * Seeds a real .po/.mo pair for a throwaway module, exactly like the real conversion tool would,
     * and returns the LanguageFileDirectory that makes it discoverable the same way a real
     * ComponentLanguageFileDirectory contribution would.
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

    public function testUpdatesAnExistingEntryAndClearsItsFuzzyFlag(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo', 'fuzzy' => true],
        ]);
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);

        $translation = $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting');
        $this->assertNotNull($translation);
        $this->assertSame('Hallo, geändert', $translation->getTranslation());
        $this->assertFalse($translation->getFlags()->has('fuzzy'));
    }

    public function testAddsANewEntryThatDidNotExistInTheFileBefore(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo'],
        ]);
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', [
            'greeting' => 'Hallo',
            'farewell' => 'Tschüss',
        ]);

        $translation = $this->loadFixturePo('wtest', 'de')->find('wtest', 'farewell');
        $this->assertNotNull($translation);
        $this->assertSame('Tschüss', $translation->getTranslation());
    }

    public function testRemovesAnEntryThatIsNoLongerInTheReplacedArray(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        $this->registerDirectoryManager($directory);

        // "farewell" is deleted server-side before replaceLangModule() rebuilds the row from what
        // remains - exactly what _deleteValues() does (see class.ilObjLanguageExt.php)
        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo']);

        $this->assertNull($this->loadFixturePo('wtest', 'de')->find('wtest', 'farewell'));
        $this->assertNotNull($this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting'));
    }

    /**
     * The pilot's "roll back one language" lever is removing just the .mo file (see README,
     * "Rollback") - the .po stays. If the write path only checked for the .po, the very next admin
     * edit would silently regenerate the .mo and undo that rollback. Gating on the .mo file too
     * (matching exactly what ilLanguage's read path checks) prevents that.
     */
    public function testIsANoOpWhenOnlyTheMoFileWasRemovedAsAPerLanguageRollback(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->registerDirectoryManager($directory);
        unlink($this->fixture_directory . '/wtest_de.mo');

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);

        $this->assertFileDoesNotExist($this->fixture_directory . '/wtest_de.mo');
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
     * serving the value it cached before this write for the rest of the request.
     */
    public function testInvalidatesIlLanguagesCacheSoTheNewValueIsVisibleImmediately(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', ['greeting' => ['value' => 'Hallo']]);
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
     * conversion tool shipped it with ("original") must leave a local_change timestamp behind.
     */
    public function testWritingADifferentValueThanTheOriginalSetsALocalChangeTimestamp(): void
    {
        $this->stubDatabaseForReplaceLangModule();
        $directory = $this->seedFixtureModule('wtest', 'de', [
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
        ]);
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);

        $translation = $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting');
        $this->assertNotNull($translation);
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($translation));
        $this->assertNotNull(LocalChangeComments::getLocalChange($translation));
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
        $this->registerDirectoryManager($directory);

        // sanity check: the fixture starts out already locally changed
        $before = $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting');
        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo, geändert']);
        $this->assertNotNull(LocalChangeComments::getLocalChange(
            $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting')
        ));

        ilObjLanguage::replaceLangModule('de', 'wtest', ['greeting' => 'Hallo']);

        $translation = $this->loadFixturePo('wtest', 'de')->find('wtest', 'greeting');
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
        $this->registerDirectoryManager($directory);

        ilObjLanguage::replaceLangModule('de', 'wtest', [
            'greeting' => 'Hallo',
            'farewell' => 'Tschüss',
        ]);

        $translation = $this->loadFixturePo('wtest', 'de')->find('wtest', 'farewell');
        $this->assertNotNull($translation);
        $this->assertNull(LocalChangeComments::getOriginal($translation));
        $this->assertNotNull(LocalChangeComments::getLocalChange($translation));
    }
}
