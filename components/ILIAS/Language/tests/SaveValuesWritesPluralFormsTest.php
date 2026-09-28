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

use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\Stub;

/**
 * End-to-end: ilObjLanguageExt::_saveValues()/_deleteValues() for a module maintained in PO files
 * with a plural message - a form key ("item [n]", see PluralFormKey) has no lng_data row of its own;
 * the row of the message's identifier (default form, "collapsed", see
 * MigratedLanguageFileSync::collapsePluralForms()) is written once via writePluralRows(). Same
 * fixture/mock-DB pattern as SaveValuesMergesOntoOverlayTest, extended with a `replace()` spy for the
 * lng_data row writePluralRows() writes.
 *
 * Runs every test in its own process for the same reason as SaveValuesMergesOntoOverlayTest
 * (CLIENT_DATA_DIR/ILIAS_ABSOLUTE_PATH cannot be redefined, and several static caches must start
 * empty).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SaveValuesWritesPluralFormsTest extends ilLanguageBaseTestCase
{
    private const string MODULE = 'ptest2';
    private const string LANG_KEY = 'de';

    private ?string $fixture_directory = null;
    private bool $created_client_data_dir_root = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
        $this->created_client_data_dir_root = MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);

        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);
        (new ReflectionClass(ilCachedLanguage::class))->getProperty('instances')->setValue(null, []);
    }

    protected function tearDown(): void
    {
        if ($this->fixture_directory !== null) {
            MigratedPoFixture::removeShippedDirectory('components/ILIAS/Language/tests/' . $this->fixture_directory);
            MigratedPoFixture::removeDirectory(
                rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/' . $this->fixture_directory
            );
        }
        if ($this->created_client_data_dir_root && defined('CLIENT_DATA_DIR') && is_dir(CLIENT_DATA_DIR)) {
            MigratedPoFixture::removeDirectory(CLIENT_DATA_DIR);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Ships self::MODULE/self::LANG_KEY with one plural message ("item"/"items", forms "Eintrag"/
     * "Einträge") and registers its LanguageFileDirectory.
     */
    private function seedShippedPluralModule(): LanguageFileDirectory
    {
        $this->fixture_directory = 'tmp-savevalues-plural-fixtures-' . bin2hex(random_bytes(4));
        $relative_path = 'components/ILIAS/Language/tests/' . $this->fixture_directory . '/';

        $catalog = new TranslationCatalog();
        $catalog->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $catalog->setHeader('Plural-Forms', 'nplurals=2; plural=(n != 1);');
        $entry = new TranslationEntry(self::MODULE, 'item');
        $entry->setPlural('items', ['Eintrag', 'Einträge']);
        $catalog->add($entry);
        MigratedPoFixture::writeShippedPo($relative_path, self::MODULE, self::LANG_KEY, $catalog);

        $directory = MigratedPoFixture::directory(self::MODULE, $relative_path);
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $directory)
        );

        return $directory;
    }

    private function stubLng(): ilLanguage
    {
        $lng = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        $this->setGlobalVariable('lng', $lng);

        return $lng;
    }

    private function seedEmptyGlobalLanguageFile(string $lang_key): void
    {
        $fake_global_file = (new ReflectionClass(ilLanguageFile::class))->newInstanceWithoutConstructor();
        $fake_global_file->setAllValues([]);
        $fake_global_file->setAllComments([]);

        $cache = (new ReflectionClass(ilLanguageFile::class))->getProperty('global_file_objects');
        $existing = $cache->isInitialized() ? $cache->getValue() : [];
        $cache->setValue(null, $existing + [$lang_key => $fake_global_file]);
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $replace_calls filled with every
     *        replace("lng_data", ...) call, in call order: [identifier, ['value' => ...,
     *        'local_change' => ..., 'remarks' => ...]] - must be passed in as an empty array
     * @param list<array{module: string, identifier: string, remarks: string}> $existing_remarks rows
     *        _getRemarks() (the "remarks IS NOT NULL" SELECT) returns
     */
    private function mockDatabaseForWrites(array &$replace_calls, array $existing_remarks = []): ilDBInterface&Stub
    {
        $lng_modules_stmt = $this->createStub(ilDBStatement::class);
        $remarks_stmt = $this->createStub(ilDBStatement::class);
        $lng_data_stmt = $this->createStub(ilDBStatement::class);
        $query_f_stmt = $this->createStub(ilDBStatement::class);

        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn($value, string $type = ''): string => "'" . (string) $value . "'");
        $db->method('in')->willReturn("module IN ('placeholder')");
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($lng_modules_stmt, $remarks_stmt, $lng_data_stmt): ilDBStatement {
                if (str_contains($sql, 'lng_modules')) {
                    return $lng_modules_stmt;
                }
                return str_contains($sql, 'remarks') ? $remarks_stmt : $lng_data_stmt;
            }
        );
        $db->method('queryF')->willReturn($query_f_stmt);
        $remaining_remarks = $existing_remarks;
        $db->method('fetchAssoc')->willReturnCallback(
            static function (ilDBStatement $statement) use ($query_f_stmt, $remarks_stmt, &$remaining_remarks): ?array {
                if ($statement === $query_f_stmt) {
                    return ['lang_array' => serialize([])];
                }
                if ($statement === $remarks_stmt) {
                    return array_shift($remaining_remarks) ?: null;
                }
                return null;
            }
        );
        $db->method('manipulate')->willReturn(0);
        $db->method('insert')->willReturn(1);

        $db->method('replace')->willReturnCallback(
            static function (string $table, array $keys, array $values) use (&$replace_calls): int {
                if ($table === 'lng_data') {
                    $replace_calls[] = [$keys['identifier'][1] ?? null, [
                        'value' => $values['value'][1] ?? null,
                        'local_change' => $values['local_change'][1] ?? null,
                        'remarks' => $values['remarks'][1] ?? null,
                    ]];
                }
                return 1;
            }
        );

        $this->setGlobalVariable('ilDB', $db);

        return $db;
    }

    // ---------------------------------------------------------------- tests

    /**
     * Only form 1 ("item [1]") is submitted with a changed value - the merged lng_data row is written
     * once, under the plain identifier "item", with the default form's (msgstr[1], count >= 2) value -
     * never one row per form.
     */
    public function testSaveValuesWritesOneMergedLngDataRowForAChangedPluralForm(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls = [];
        $this->mockDatabaseForWrites($replace_calls);

        $unwritten = ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [self::MODULE . $lng->separator . 'item [1]' => 'Einträge NEU'],
            []
        );

        $this->assertSame([], $unwritten, 'the overlay of the migrated module must be written');
        $item_calls = array_values(array_filter($replace_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls, 'exactly one lng_data row for the plural message, not one per form');
        $this->assertSame('Einträge NEU', $item_calls[0][1]['value']);
        $this->assertNotNull($item_calls[0][1]['local_change'], 'marked as a local change');
    }

    /**
     * Once every form is resubmitted with its shipped value again (both back to shipped), the
     * lng_data row of the plural message is written with the shipped default value and NO local
     * change - mirroring an ordinary key being reset via resolveLocalValue()/databaseValues().
     */
    public function testSaveValuesResetsThePluralRowWhenEveryFormMatchesTheShippedValueAgain(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls_1 = [];
        $this->mockDatabaseForWrites($replace_calls_1);
        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [self::MODULE . $lng->separator . 'item [1]' => 'Einträge NEU'],
            []
        );

        // a second save, in a fresh mocked DB, resubmitting both forms as their shipped values
        $replace_calls_2 = [];
        $this->mockDatabaseForWrites($replace_calls_2);
        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [0]' => 'Eintrag',
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge',
            ],
            []
        );

        $item_calls = array_values(array_filter($replace_calls_2, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls);
        $this->assertSame('Einträge', $item_calls[0][1]['value']);
        $this->assertNull($item_calls[0][1]['local_change'], 'reset to the shipped value is not a local change');
    }

    /**
     * The remark of the merged lng_data row is the first form's posted remark that actually DIFFERS
     * from the stored one (not merely the first non-empty one) - here form 0's posted remark equals
     * its stored (absent) one, so form 1's wins.
     */
    public function testSaveValuesRemarkOfThePluralRowIsTheFirstFormRemarkThatDiffersFromStored(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls = [];
        $this->mockDatabaseForWrites($replace_calls);

        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [0]' => 'Eintrag NEU',
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge NEU',
            ],
            [self::MODULE . $lng->separator . 'item [0]' => '', self::MODULE . $lng->separator . 'item [1]' => 'Bemerkung zu Form 1'],
        );

        $item_calls = array_values(array_filter($replace_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls);
        $this->assertSame('Bemerkung zu Form 1', $item_calls[0][1]['remarks']);
    }

    /**
     * Posting an empty remark for a form whose stored remark is non-empty DELETES the remark (written
     * as NULL) - '' is a value that can differ from a stored remark like any other.
     */
    public function testSaveValuesAnEmptyPostedRemarkThatDiffersFromStoredDeletesIt(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls = [];
        $this->mockDatabaseForWrites($replace_calls, [
            ['module' => self::MODULE, 'identifier' => 'item', 'remarks' => 'Alte Bemerkung'],
        ]);

        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [0]' => 'Eintrag NEU',
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge',
            ],
            [self::MODULE . $lng->separator . 'item [0]' => ''],
        );

        $item_calls = array_values(array_filter($replace_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls);
        $this->assertNull($item_calls[0][1]['remarks']);
    }

    /**
     * No posted remark differs from the stored one (form 0's is not posted at all, form 1's matches
     * the stored value exactly) - the stored remark is kept as is on the row a value change still
     * writes.
     */
    public function testSaveValuesKeepsTheStoredRemarkWhenNoPostedRemarkDiffersFromIt(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls = [];
        $this->mockDatabaseForWrites($replace_calls, [
            ['module' => self::MODULE, 'identifier' => 'item', 'remarks' => 'Bestehende Bemerkung'],
        ]);

        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [0]' => 'Eintrag',
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge NEU',
            ],
            [self::MODULE . $lng->separator . 'item [1]' => 'Bestehende Bemerkung'],
        );

        $item_calls = array_values(array_filter($replace_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls);
        $this->assertSame('Bestehende Bemerkung', $item_calls[0][1]['remarks']);
    }

    /**
     * A pure remark change - no form's value differs from its current one at all - still writes the
     * lng_data row (so the new remark actually reaches the database), even though the merged default
     * value stays exactly the shipped one (and therefore local_change stays null).
     */
    public function testSaveValuesAPureRemarkChangeWithNoValueChangeStillWritesTheRow(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls = [];
        $this->mockDatabaseForWrites($replace_calls);

        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [0]' => 'Eintrag',
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge',
            ],
            [self::MODULE . $lng->separator . 'item [1]' => 'Neue Bemerkung'],
        );

        $item_calls = array_values(array_filter($replace_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls, 'the row must be written for the remark alone');
        $this->assertSame('Einträge', $item_calls[0][1]['value']);
        $this->assertNull($item_calls[0][1]['local_change'], 'the value itself did not change');
        $this->assertSame('Neue Bemerkung', $item_calls[0][1]['remarks']);
    }

    /**
     * The exact scenario named in review: form 0's VALUE changes (form 1's does not - so the merged
     * default value, form 1, stays the shipped one and local_change stays null), form 1's REMARK
     * changes - the row is written once, with the new remark from form 1.
     */
    public function testSaveValuesFormZeroValueChangedFormOneOnlyRemarkChangedTakesTheRemarkFromFormOne(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls = [];
        $this->mockDatabaseForWrites($replace_calls);

        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [0]' => 'Eintrag NEU',
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge',
            ],
            [self::MODULE . $lng->separator . 'item [1]' => 'Bemerkung aus Form 1'],
        );

        $item_calls = array_values(array_filter($replace_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls);
        $this->assertSame('Einträge', $item_calls[0][1]['value'], 'the default form (1) itself did not change');
        $this->assertNull($item_calls[0][1]['local_change']);
        $this->assertSame('Bemerkung aus Form 1', $item_calls[0][1]['remarks']);
    }

    /**
     * A form key whose index the shipped message does not declare at all (here: "item [5]" while the
     * shipped message only has forms 0/1, e.g. a stale form left over after the module's Plural-Forms
     * rule shrank) is silently dropped - never written as a phantom row, and never crashes the save
     * of the other, valid forms in the same request.
     */
    public function testSaveValuesDropsAFormKeyWithAnIndexBeyondTheShippedFormCount(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $replace_calls = [];
        $this->mockDatabaseForWrites($replace_calls);

        $unwritten = ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge NEU',
                self::MODULE . $lng->separator . 'item [5]' => 'Geister-Form',
            ],
            []
        );

        $this->assertSame([], $unwritten);
        $item_calls = array_values(array_filter($replace_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls, 'exactly one row for the real message - the orphan form must not create its own');
        $this->assertSame('Einträge NEU', $item_calls[0][1]['value']);
    }

    /**
     * Deleting one form key ("item [1]") of a locally changed plural message - a missing form counts
     * as its shipped value (see MigratedLanguageFileSync::collapsePluralForms()) - lands back at the
     * module's plain default-form value with no local change, and the overlay entry for the message
     * disappears entirely (nothing left to keep as a local change).
     */
    public function testDeleteValuesOfOneFormRevertsThePluralRowToTheShippedDefaultAndDropsTheOverlayEntry(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $save_calls = [];
        $this->mockDatabaseForWrites($save_calls);
        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [self::MODULE . $lng->separator . 'item [1]' => 'Einträge NEU'],
            []
        );
        $overlay = TranslationCatalog::fromPoFile(
            rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/' . $this->fixture_directory
                . '/' . self::MODULE . '_' . self::LANG_KEY . '.po'
        );
        $this->assertNotNull($overlay->find(null, 'item'), 'precondition: the overlay holds the local change');

        $delete_calls = [];
        $this->mockDatabaseForWrites($delete_calls);
        $unwritten = ilObjLanguageExt::_deleteValues(self::LANG_KEY, [self::MODULE . $lng->separator . 'item [1]' => '']);

        $this->assertSame([], $unwritten);
        $item_calls = array_values(array_filter($delete_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls, 'exactly one merged row, not a separate row per form');
        $this->assertSame('Einträge', $item_calls[0][1]['value'], 'back to the shipped default form value');
        $this->assertNull($item_calls[0][1]['local_change']);

        $overlay_after_path = rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/' . $this->fixture_directory
            . '/' . self::MODULE . '_' . self::LANG_KEY . '.po';
        $this->assertFalse(is_file($overlay_after_path), 'no local change left at all - the whole overlay is removed');
    }

    /**
     * _deleteValues() rewrites the plural message's lng_data row too (see writePluralRows()) - its
     * existing remark (from lng_data, since a delete submits no new remarks of its own) must survive
     * that rewrite rather than being wiped just because a form was deleted.
     */
    public function testDeleteValuesKeepsTheExistingRemarkOfThePluralRow(): void
    {
        $this->seedShippedPluralModule();
        $lng = $this->stubLng();
        $this->seedEmptyGlobalLanguageFile(self::LANG_KEY);
        $save_calls = [];
        $this->mockDatabaseForWrites($save_calls);
        ilObjLanguageExt::_saveValues(
            self::LANG_KEY,
            [
                self::MODULE . $lng->separator . 'item [0]' => 'Eintrag NEU',
                self::MODULE . $lng->separator . 'item [1]' => 'Einträge NEU',
            ],
            []
        );

        $delete_calls = [];
        $this->mockDatabaseForWrites($delete_calls, [
            ['module' => self::MODULE, 'identifier' => 'item', 'remarks' => 'Alte Bemerkung'],
        ]);
        ilObjLanguageExt::_deleteValues(self::LANG_KEY, [self::MODULE . $lng->separator . 'item [1]' => '']);

        $item_calls = array_values(array_filter($delete_calls, static fn(array $call): bool => $call[0] === 'item'));
        $this->assertCount(1, $item_calls);
        $this->assertSame('Alte Bemerkung', $item_calls[0][1]['remarks']);
    }
}
