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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\Stub;

/**
 * T10: ilObjLanguageExt::currentModuleContent() (~975-1036) - the "current content a partial
 * save/delete is applied to" - and the $merge_onto_current_content behavior of the private
 * saveValues()/of _deleteValues() (~1093-1102).
 *
 * Covers:
 * - a migrated module's overlay is preferred over a differing lng_modules row - currentModuleContent()
 *   never even queries the database once a readable overlay was found (see its first branch);
 * - a migrated module with neither a readable overlay nor a lng_modules row falls back to lng_data
 *   (always dual-written) - proven through the public _deleteValues(), whose array_diff_key() must
 *   then exclude the deleted key from that lng_data-sourced content;
 * - saveValues()'s private $merge_onto_current_content=false (used by importLanguageFile()'s "delete"
 *   mode, see saveValues()'s own docblock) never merges the new entries onto the existing lng_modules
 *   content at all - a key that used to exist but is absent from the new entries must be gone
 *   afterward, not preserved - unlike the default $merge_onto_current_content=true.
 *
 * Runs every test method in its own separate process: a full-suite run can have CLIENT_DATA_DIR
 * already defined by an earlier, unrelated test class sharing the same process (e.g.
 * Filesystem/tests/ilServicesFileSystemTest.php or Test/tests/ilTestBaseTestCaseTrait.php define it
 * as /var/iliasdata) - without this, MigratedPoFixture::ensureClientDataDirDefinedOrSkip()'s guard
 * would then skip every single test below for the rest of that process, since a PHP constant cannot
 * be redefined. A fresh process per test method guarantees CLIENT_DATA_DIR starts out undefined here,
 * exactly like a lone test run.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SaveValuesMergesOntoOverlayTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
        MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);

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

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // fixtures
    // -----------------------------------------------------------------

    /**
     * @param array<string, string> $shipped_entries
     * @param array<string, string>|null $overlay_entries null => no overlay at all (not even a
     *        readable one) for this module/language
     */
    private function seedShippedAndOverlay(
        string $module,
        array $shipped_entries,
        ?array $overlay_entries
    ): LanguageFileDirectory {
        $this->fixture_directory ??= 'tmp-savevalues-fixtures-' . bin2hex(random_bytes(4));
        $relative_path = 'components/ILIAS/Language/tests/' . $this->fixture_directory . '/';

        MigratedPoFixture::writeShippedPo(
            $relative_path,
            $module,
            'de',
            MigratedPoFixture::catalog($module, $shipped_entries)
        );

        if ($overlay_entries !== null) {
            $overlay_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/' . $relative_path;
            MigratedPoFixture::writePair(
                $overlay_dir . $module . '_de',
                MigratedPoFixture::catalog($module, $overlay_entries)
            );
        }

        return MigratedPoFixture::directory($module, $relative_path);
    }

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    private function callCurrentModuleContent(string $lang_key, string $module): array
    {
        return (new ReflectionClass(ilObjLanguageExt::class))
            ->getMethod('currentModuleContent')
            ->invoke(null, $lang_key, $module);
    }

    /**
     * @return array{0: string, 1: string} module/topic separator, an ilLanguage stand-in whose
     *         constructor never ran (its default $separator property is enough for every caller here)
     */
    private function stubLng(): ilLanguage
    {
        $lng = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        $this->setGlobalVariable('lng', $lng);

        return $lng;
    }

    /**
     * Builds the $ilDB mock every test below needs: the three distinct SELECTs
     * ilObjLanguageExt/ilObjLanguage issue (the per-module lng_modules row, the lng_data "remarks"
     * SELECT and the plain lng_data values SELECT) are told apart by their SQL text, exactly like
     * SaveValuesRecreatesLngModulesAfterDeleteModeImportTest::mockDatabaseForSaveValues() - extended
     * here with a controllable lng_modules row and lng_data rows so the merge behavior itself can be
     * asserted, not just that nothing crashes.
     *
     * @param array<string, string>|null $lng_modules_row the (unserialized) content of the module's
     *        existing lng_modules row; null = no row at all
     * @param list<array{identifier: string, value: string}> $lng_data_rows rows the plain lng_data
     *        values SELECT returns, in order
     */
    private function mockDatabaseForWrites(?array $lng_modules_row, array $lng_data_rows = []): ilDBInterface&Stub
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
                if (str_contains($sql, 'remarks')) {
                    return $remarks_stmt;
                }
                return $lng_data_stmt;
            }
        );
        $db->method('queryF')->willReturn($query_f_stmt);

        $rows = $lng_data_rows;
        $db->method('fetchAssoc')->willReturnCallback(
            function (ilDBStatement $statement) use (
                $lng_modules_stmt,
                $remarks_stmt,
                $lng_data_stmt,
                $query_f_stmt,
                $lng_modules_row,
                &$rows
            ) {
                if ($statement === $query_f_stmt) {
                    // writeLangModule()'s own self-check only needs an array to unserialize to
                    return ['lang_array' => serialize([])];
                }
                if ($statement === $lng_modules_stmt) {
                    return $lng_modules_row === null ? null : ['lang_array' => serialize($lng_modules_row)];
                }
                if ($statement === $remarks_stmt) {
                    return null;
                }
                if ($statement === $lng_data_stmt) {
                    $row = array_shift($rows);
                    return $row ?? null;
                }
                return null;
            }
        );
        $db->method('manipulate')->willReturn(0);
        $db->method('insert')->willReturn(1);

        $this->setGlobalVariable('ilDB', $db);

        return $db;
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

    private function callSaveValues(
        string $lang_key,
        array $values,
        array $remarks,
        bool $refresh_original_from_shipped,
        bool $merge_onto_current_content
    ): array {
        return (new ReflectionClass(ilObjLanguageExt::class))
            ->getMethod('saveValues')
            ->invoke(null, $lang_key, $values, $remarks, $refresh_original_from_shipped, $merge_onto_current_content);
    }

    // -----------------------------------------------------------------
    // currentModuleContent(): overlay vs. lng_modules row
    // -----------------------------------------------------------------

    /**
     * Mutation this catches: swapping the overlay branch's early `return` for a fall-through into the
     * DB path (or dropping the `$overlay !== null` check) would silently prefer/merge in the stale
     * lng_modules row instead. Asserting `->never()` on `query()` also catches a mutation that removes
     * the `if ($is_migrated)` guard, not merely one that ignores its result.
     */
    public function testOverlayValueIsUsedEvenWhenItDiffersFromTheLngModulesRowAndTheDatabaseIsNeverConsulted(): void
    {
        $directory = $this->seedShippedAndOverlay(
            'ctest',
            ['greeting' => 'Shipped'],
            ['greeting' => 'Aus dem Overlay']
        );
        $this->registerDirectoryManager($directory);

        $db = $this->createMock(ilDBInterface::class);
        $db->expects($this->never())->method('query');
        $this->setGlobalVariable('ilDB', $db);

        $this->assertSame(
            ['greeting' => 'Aus dem Overlay'],
            $this->callCurrentModuleContent('de', 'ctest')
        );
    }

    /**
     * The flip side of the guarantee above, driven through the public _deleteValues(): a migrated
     * module (shipped .po exists) with neither a readable overlay (none was ever written here) nor a
     * lng_modules row must be rebuilt from lng_data - and the deleted key excluded from what is written
     * back, not merely from what was read.
     *
     * Mutation this catches: dropping the lng_data fallback (returning `[]` whenever there is no
     * lng_modules row, regardless of $is_migrated) would collapse the overlay replaceLangModule()
     * writes to only the empty array, losing "farewell" instead of keeping it.
     */
    public function testDeleteValuesWithoutOverlayAndWithoutLngModulesRowFallsBackToLngDataExcludingDeletedKeys(): void
    {
        $directory = $this->seedShippedAndOverlay('ctest2', ['greeting' => 'Shipped'], null);
        $this->registerDirectoryManager($directory);
        $lng = $this->stubLng();

        $db = $this->mockDatabaseForWrites(null, [
            ['identifier' => 'greeting', 'value' => 'Aus lng_data'],
            ['identifier' => 'farewell', 'value' => 'Tschüss aus lng_data'],
        ]);

        $captured = null;
        $db->method('insert')->willReturnCallback(
            static function (string $table, array $values) use (&$captured): int {
                if ($table === 'lng_modules') {
                    $captured = unserialize($values['lang_array'][1] ?? '', ['allowed_classes' => false]);
                }
                return 1;
            }
        );

        ilObjLanguageExt::_deleteValues('de', ['ctest2' . $lng->separator . 'greeting' => 'irrelevant']);

        $this->assertSame(['farewell' => 'Tschüss aus lng_data'], $captured);
    }

    // -----------------------------------------------------------------
    // saveValues(): $merge_onto_current_content
    // -----------------------------------------------------------------

    /**
     * The scenario saveValues()'s own docblock describes for "delete"-mode imports: $to_save is each
     * module's complete new content, so it must never be merged onto whatever is still in the
     * lng_modules row - a key present there but absent from the new entries must be gone afterward.
     *
     * Mutation this catches: hard-coding `$merge_onto_current_content` to `true` (or always calling
     * currentModuleContent() regardless of the flag) would keep "farewell" in the written result.
     */
    public function testMergeOntoCurrentContentFalseDropsAKeyThatIsNotInTheNewEntries(): void
    {
        $lang_key = 'zz_merge_false_test';
        $this->seedEmptyGlobalLanguageFile($lang_key);
        $lng = $this->stubLng();
        $db = $this->mockDatabaseForWrites(['greeting' => 'Alt', 'farewell' => 'Tschüss']);

        $captured = null;
        $db->method('insert')->willReturnCallback(
            static function (string $table, array $values) use (&$captured): int {
                if ($table === 'lng_modules') {
                    $captured = unserialize($values['lang_array'][1] ?? '', ['allowed_classes' => false]);
                }
                return 1;
            }
        );

        $this->callSaveValues(
            $lang_key,
            ['wtest' . $lng->separator . 'greeting' => 'Neu'],
            [],
            false,
            false
        );

        $this->assertSame(['greeting' => 'Neu'], $captured);
    }

    /**
     * The default, contrasted directly against the test above with the exact same starting
     * lng_modules row: with $merge_onto_current_content=true (an ordinary save/import, never
     * "delete"-mode) the untouched "farewell" key must survive.
     */
    public function testMergeOntoCurrentContentTrueKeepsAKeyThatIsNotInTheNewEntries(): void
    {
        $lang_key = 'zz_merge_true_test';
        $this->seedEmptyGlobalLanguageFile($lang_key);
        $lng = $this->stubLng();
        $db = $this->mockDatabaseForWrites(['greeting' => 'Alt', 'farewell' => 'Tschüss']);

        $captured = null;
        $db->method('insert')->willReturnCallback(
            static function (string $table, array $values) use (&$captured): int {
                if ($table === 'lng_modules') {
                    $captured = unserialize($values['lang_array'][1] ?? '', ['allowed_classes' => false]);
                }
                return 1;
            }
        );

        $this->callSaveValues(
            $lang_key,
            ['wtest' . $lng->separator . 'greeting' => 'Neu'],
            [],
            false,
            true
        );

        $this->assertSame(['greeting' => 'Neu', 'farewell' => 'Tschüss'], $captured);
    }
}
