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
use Gettext\Translation;
use Gettext\Translations;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Covers ilObjLanguage::_getLastLocalChange()'s new PO/MO-aware behavior (see
 * components/ILIAS/Language/tools/po-migration/README.md, "Lokale Änderungen nachvollziehen"): a
 * migrated module's .po file can carry a local_change that lng_data.local_change never sees at all
 * (direct .po edit, or any future tool bypassing replaceLangModule()'s DB dual-write). The overview
 * admin table ("Sprachen" -> "Letzte Änderung") must not silently ignore that.
 *
 * The bulk of the new logic lives in the private static _getLastMigratedLocalChange() helper, which
 * is exercised directly, thoroughly, via reflection (same pattern PoMigrationLoadLanguageModuleTest
 * uses for ilLanguage::loadFromMigratedLanguageFile()). _getLastLocalChange() itself is additionally
 * covered end-to-end with a stubbed $ilDB, to lock down the match()/max() merge between the DB value
 * and the PO value - see stubDatabase() below for why that is practical here (unlike, say, a method
 * that loops over multiple query() calls).
 *
 * Uses throwaway fixture modules (not any real pilot data) so these tests never touch real files.
 */
class PoLastLocalChangeTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;

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
     * Builds a real .po/.mo pair for a throwaway module, exactly like the real conversion tool
     * would, and returns the LanguageFileDirectory that makes it discoverable the same way a real
     * ComponentLanguageFileDirectory contribution would.
     *
     * @param array<string, array{value: string, original?: string}> $entries keyed by identifier;
     *   'original', when given, seeds LocalChangeComments::setOriginal() so refresh() below has a
     *   baseline to compare against.
     * @param array<string, string> $local_changes identifier => ISO-8601 UTC local_change timestamp
     *   ('Y-m-d\TH:i:s\Z') to stamp onto that entry via LocalChangeComments::refresh(), simulating a
     *   direct .po edit at that point in time.
     */
    private function seedFixtureModule(
        string $module,
        string $lang_key,
        array $entries,
        array $local_changes = []
    ): LanguageFileDirectory {
        $this->fixture_directory ??= __DIR__ . '/tmp-lastchange-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $entry) {
            $translation = Translation::create($module, $identifier)->translate($entry['value']);
            if (isset($entry['original'])) {
                LocalChangeComments::setOriginal($translation, $entry['original']);
            }
            if (isset($local_changes[$identifier])) {
                $now = DateTimeImmutable::createFromFormat(
                    'Y-m-d\TH:i:s\Z',
                    $local_changes[$identifier],
                    new DateTimeZone('UTC')
                );
                // refresh() only stamps local_change when the new value differs from the previous
                // one it is given - an empty previous value (or the original, if none was seeded)
                // reliably triggers that regardless of $entry['value']'s actual content.
                $previous = $entry['original'] ?? '';
                LocalChangeComments::refresh(
                    $translation,
                    $previous === $entry['value'] ? $previous . ' (old)' : $previous,
                    $entry['value'],
                    $now
                );
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

    private function invokeGetLastMigratedLocalChange(string $lang_key): ?string
    {
        $method = (new ReflectionClass(ilObjLanguage::class))->getMethod('_getLastMigratedLocalChange');
        return $method->invoke(null, $lang_key);
    }

    /**
     * _getLastLocalChange() issues exactly one query() call (no loop, no per-row dispatch), so a
     * single canned fetchRow() result is enough to pin down the merge logic end-to-end - unlike a
     * method that queries in a loop (where a mock's canned return sequence would silently couple the
     * test to call order), this is a single, unambiguous stub.
     */
    private function stubDatabase(?string $db_last_change): void
    {
        $statement = $this->createStub(ilDBStatement::class);
        $statement->method('fetchRow')->willReturn(
            $db_last_change === null ? [] : ['last_change' => $db_last_change]
        );

        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            static fn(mixed $value, string $type): string => "'" . (string) $value . "'"
        );
        $db->method('query')->willReturn($statement);

        $this->setGlobalVariable('ilDB', $db);
    }

    // -----------------------------------------------------------------
    // _getLastMigratedLocalChange() - the actually-new logic
    // -----------------------------------------------------------------

    public function testReturnsTheNormalizedTimestampForAModuleWithOneLocallyChangedEntry(): void
    {
        $directory = $this->seedFixtureModule(
            'lctest',
            'de',
            ['greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo']],
            ['greeting' => '2026-09-21T10:00:00Z']
        );
        $this->registerDirectoryManager($directory);

        $this->assertSame('2026-09-21 10:00:00', $this->invokeGetLastMigratedLocalChange('de'));
    }

    public function testReturnsTheMaximumAcrossTwoEntriesNotTheFirstOrLastIterated(): void
    {
        // The chronologically later change ("farewell") is added to the array before the earlier
        // one ("greeting") - iteration order alone must not decide the winner, only the timestamp.
        $directory = $this->seedFixtureModule(
            'lctest',
            'de',
            [
                'farewell' => ['value' => 'Tschüss, geändert', 'original' => 'Tschüss'],
                'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo'],
            ],
            [
                'farewell' => '2026-09-21T18:00:00Z',
                'greeting' => '2026-09-21T10:00:00Z',
            ]
        );
        $this->registerDirectoryManager($directory);

        $this->assertSame('2026-09-21 18:00:00', $this->invokeGetLastMigratedLocalChange('de'));
    }

    public function testReturnsTheMaximumAcrossTwoContributedModules(): void
    {
        $module_a = $this->seedFixtureModule(
            'lca',
            'de',
            ['greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo']],
            ['greeting' => '2026-09-21T10:00:00Z']
        );
        $module_b = $this->seedFixtureModule(
            'lcb',
            'de',
            ['farewell' => ['value' => 'Tschüss, geändert', 'original' => 'Tschüss']],
            ['farewell' => '2026-09-22T09:30:00Z']
        );
        $this->registerDirectoryManager($module_a, $module_b);

        $this->assertSame('2026-09-22 09:30:00', $this->invokeGetLastMigratedLocalChange('de'));
    }

    public function testReturnsNullWhenNoEntryOfTheMigratedModuleHasALocalChange(): void
    {
        $directory = $this->seedFixtureModule('lctest', 'de', [
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
        ]);
        $this->registerDirectoryManager($directory);

        $this->assertNull($this->invokeGetLastMigratedLocalChange('de'));
    }

    public function testSkipsAContributedDirectoryMissingTheMoOrPoFileForTheRequestedLanguage(): void
    {
        $directory = $this->seedFixtureModule(
            'lctest',
            'de',
            ['greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo']],
            ['greeting' => '2026-09-21T10:00:00Z']
        );
        $this->registerDirectoryManager($directory);

        // no 'lctest_fr.po'/'lctest_fr.mo' pair exists anywhere - must not error, must contribute
        // nothing for that language.
        $this->assertNull($this->invokeGetLastMigratedLocalChange('fr'));
    }

    public function testReturnsNullWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        $this->assertNull($this->invokeGetLastMigratedLocalChange('de'));
    }

    public function testSkipsATranslationWhoseContextDoesNotMatchTheModule(): void
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-lastchange-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create('lctest', 'de');
        // Foreign context: same .po file, but a translation entry belonging to a *different* module
        // (e.g. left behind by a merge/copy mistake) - must never be able to leak a local_change into
        // "lctest"'s result.
        $foreign = Translation::create('other_module', 'greeting')->translate('Hallo, geändert');
        LocalChangeComments::setOriginal($foreign, 'Hallo');
        LocalChangeComments::refresh($foreign, 'Hallo', 'Hallo, geändert', new DateTimeImmutable('2026-09-21T10:00:00Z'));
        $translations->add($foreign);

        $base_path = $this->fixture_directory . '/lctest_de';
        (new PoGenerator())->generateFile($translations, $base_path . '.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $base_path . '.mo');

        $relative_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/';
        $directory = new class ('lctest', $relative_path) implements LanguageFileDirectory {
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
        $this->registerDirectoryManager($directory);

        $this->assertNull($this->invokeGetLastMigratedLocalChange('de'));
    }

    // -----------------------------------------------------------------
    // _getLastLocalChange() - merge of the DB value and the PO value
    // -----------------------------------------------------------------

    public function testPoValueWinsWhenItIsLaterThanTheDbValue(): void
    {
        $this->stubDatabase('2026-09-20 08:00:00');
        $directory = $this->seedFixtureModule(
            'lctest',
            'de',
            ['greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo']],
            ['greeting' => '2026-09-21T10:00:00Z']
        );
        $this->registerDirectoryManager($directory);

        $this->assertSame('2026-09-21 10:00:00', ilObjLanguage::_getLastLocalChange('de'));
    }

    public function testDbValueWinsWhenItIsLaterThanThePoValue(): void
    {
        $this->stubDatabase('2026-09-22 12:00:00');
        $directory = $this->seedFixtureModule(
            'lctest',
            'de',
            ['greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo']],
            ['greeting' => '2026-09-21T10:00:00Z']
        );
        $this->registerDirectoryManager($directory);

        $this->assertSame('2026-09-22 12:00:00', ilObjLanguage::_getLastLocalChange('de'));
    }

    /**
     * Regression safety net for the pre-existing (pre-PO-pilot) behavior: with no migrated module at
     * all contributing anything, the DB value must still be returned exactly as before.
     */
    public function testReturnsTheDbValueUnchangedWhenNoMigratedModuleContributesAnything(): void
    {
        $this->stubDatabase('2026-09-20 08:00:00');
        // No LanguageFileDirectoryManager registered at all - matches a plain installation that has
        // no PO/MO pilot contribution wired up.

        $this->assertSame('2026-09-20 08:00:00', ilObjLanguage::_getLastLocalChange('de'));
    }

    /**
     * The "" sentinel is not incidental: ilLanguageFolderTable relies on it to disable the "reset
     * local changes" row action when a language truly has no local changes anywhere.
     */
    public function testReturnsEmptyStringWhenNeitherSourceHasAnyLocalChange(): void
    {
        $this->stubDatabase(null);
        $directory = $this->seedFixtureModule('lctest', 'de', [
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
        ]);
        $this->registerDirectoryManager($directory);

        $this->assertSame('', ilObjLanguage::_getLastLocalChange('de'));
    }
}
