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

/**
 * Regression test for the "delete"-mode import bug in
 * ilObjLanguageExt::_saveValues() (class.ilObjLanguageExt.php, ~line 519 in the fixed version):
 *
 * ilObjLanguageExt::importLanguageFile($file, "delete") first wipes ALL lng_data AND lng_modules
 * rows for the whole language via raw SQL (see the `case "delete":` branch in importLanguageFile())
 * - *before* calling _saveValues(). _saveValues() then iterates the imported values module by module
 * and, for each module, used to SELECT its (now nonexistent) lng_modules row and, when that SELECT
 * came back empty, `continue` - skipping ilObjLanguage::replaceLangModule() entirely, which is the
 * only place that actually writes the lng_modules row. Because "delete" mode wipes lng_modules for
 * the *entire* language up front, that guard fired for literally every module afterward, so
 * replaceLangModule() was never reached during a "delete"-mode import at all: lng_data ended up
 * correctly repopulated, but lng_modules stayed completely empty for that language. Since
 * ilCachedLanguage builds its per-language module cache from lng_modules, $lng->txt() then returned
 * only the "-topic-" placeholder for every non-migrated module until the language was fully
 * reinstalled.
 *
 * The fix replaces the `if (!$row) { ...; continue; }` guard with
 * `$entries = self::_mergeLanguageEntriesFromRow($row ?: null, $entries);` -
 * _mergeLanguageEntriesFromRow() already treats a missing/null row as "nothing to merge" and returns
 * $entries unchanged, so replaceLangModule() below is now always reached and (re-)creates the
 * lng_modules row from scratch - its own DELETE+INSERT never depended on a prior row existing.
 *
 * Scope note: this drives ilObjLanguageExt::_saveValues() directly rather than the full
 * importLanguageFile($file, "delete") end-to-end, mirroring the documented tradeoff in
 * SyncMigratedFilesAfterDeleteModeImportTest.php's class docblock for the same reason. Reaching
 * _saveValues() through importLanguageFile() would additionally require exercising
 * ilObjLanguage::_deleteLangData(), a real ilLanguageFile read via
 * ilLanguageFile::_getGlobalLanguageFile(), and ilObjLanguageExt::_getModules() - none of which
 * participate in the bug this test targets. Instead, this test reproduces exactly the DB state that
 * exists right after "delete" mode's raw
 * `DELETE FROM lng_modules WHERE lang_key = ...` has run: the SELECT that _saveValues() issues for
 * the module under test comes back with no row at all (mocked to return null from
 * ilDBInterface::fetchAssoc()), which is precisely the condition the old `continue` guarded on. What
 * _saveValues() does with $a_values (build lng_data writes, then loop $save_array by module) is
 * otherwise unchanged and exercised for real - only the underlying DB is a mock, matching the rest of
 * this test suite (see ilLanguageBaseTestCase; there is no real DB in this suite).
 *
 * Regression verification (bug reproduction without touching the fixed source): mentally replacing
 * the current `$entries = self::_mergeLanguageEntriesFromRow($row ?: null, $entries);` line with the
 * old `if (!$row) { continue; }` shows testRecreatesTheLngModulesRowForAModulePresentInTheImport()
 * would then fail - the loop would `continue` right after the SELECT (which is mocked to return no
 * row for "testmodule"), ilObjLanguage::replaceLangModule() would never run, and neither
 * $ilDB->manipulate() (the DELETE inside replaceLangModule()) nor $ilDB->insert() (with "lng_modules")
 * would ever be called - failing both the `$this->once()` expectation on insert() and the assertion on
 * its captured lang_array payload below.
 */
class SaveValuesRecreatesLngModulesAfterDeleteModeImportTest extends ilLanguageBaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ilCachedLanguage::getInstance() is a process-wide singleton keyed by lang_key
        // (static::$instances) - reset it so an instance built for this lang_key by an earlier test
        // (or an earlier method in this file) can never leak in and short-circuit our DB mock's
        // expectations via its own already-cached state.
        $instances = (new ReflectionClass(ilCachedLanguage::class))->getProperty('instances');
        $instances->setAccessible(true);
        $instances->setValue(null, []);
    }

    /**
     * Seeds ilLanguageFile::_getGlobalLanguageFile()'s static per-lang_key cache with an empty,
     * constructor-bypassed fake so _saveValues() never touches a real lang/ilias_<key>.lang file on
     * disk - this test is only concerned with the lng_modules write path, not with merging in global
     * file values.
     */
    private function seedEmptyGlobalLanguageFile(string $lang_key): void
    {
        $fake_global_file = (new ReflectionClass(ilLanguageFile::class))->newInstanceWithoutConstructor();
        $fake_global_file->setAllValues([]);
        $fake_global_file->setAllComments([]);

        $cache = (new ReflectionClass(ilLanguageFile::class))->getProperty('global_file_objects');
        $cache->setAccessible(true);
        $existing = $cache->isInitialized() ? $cache->getValue() : [];
        $cache->setValue(null, $existing + [$lang_key => $fake_global_file]);
    }

    /**
     * Builds the ilDBInterface mock that makes _saveValues() -> ilObjLanguage::replaceLangModule()
     * runnable end-to-end. The three ilDB::query() calls _saveValues() issues (the db_values SELECT
     * from lng_data via _getValues(), the db_comments SELECT from lng_data via _getRemarks(), and the
     * per-module lng_modules SELECT) are told apart by their SQL text, since each needs a different
     * canned result; everything else is a generic stand-in that is only exercised for its side effect
     * of not throwing.
     *
     * The one call that matters for this test's assertion is insert() with table "lng_modules" -
     * ilObjLanguage::replaceLangModule() is the only code path that issues it, and it is only reached
     * if the fix is in place (see this class' docblock).
     */
    private function mockDatabaseForSaveValues(): \PHPUnit\Framework\MockObject\MockObject
    {
        $empty_row_statement = $this->createStub(ilDBStatement::class);
        $empty_row_statement->method('fetchRow')->willReturn(null);

        $remarks_statement = $this->createStub(ilDBStatement::class);
        $lng_modules_select_statement = $this->createStub(ilDBStatement::class);
        $query_f_statement = $this->createStub(ilDBStatement::class);

        $db = $this->createMock(ilDBInterface::class);

        $db->method('quote')->willReturnCallback(
            static fn($value, string $type): string => "'" . (string) $value . "'"
        );

        $db->method('query')->willReturnCallback(
            static function (string $sql) use (
                $empty_row_statement,
                $remarks_statement,
                $lng_modules_select_statement
            ) {
                if (str_contains($sql, 'lng_modules')) {
                    return $lng_modules_select_statement;
                }
                if (str_contains($sql, 'remarks')) {
                    return $remarks_statement;
                }
                // the db_values SELECT issued by _getValues() ("SELECT module, identifier, value FROM lng_data ...")
                return $empty_row_statement;
            }
        );

        // Used both by ilCachedLanguage::readFromDB() (called from replaceLangModule() via
        // ilCachedLanguage::getInstance()->deleteInCache()) and by replaceLangModule()'s own
        // post-insert self-check SELECT - a single generic stub statement is enough for both, they are
        // differentiated below only via fetchAssoc()/fetchObject().
        $db->method('queryF')->willReturn($query_f_statement);

        // No pre-existing lng_modules row for "testmodule" - this is the exact condition the old,
        // buggy `if (!$row) { ...; continue; }` guarded on (see this class' docblock). No pre-existing
        // remarks either.
        $db->method('fetchAssoc')->willReturnCallback(
            static function (ilDBStatement $statement) use (
                $remarks_statement,
                $lng_modules_select_statement,
                $query_f_statement
            ) {
                if ($statement === $query_f_statement) {
                    // replaceLangModule()'s self-check: must unserialize to an array or it treats the
                    // write as failed (mantis #20046/#19140).
                    return ['lang_array' => serialize(['greeting' => 'Hallo, neu'])];
                }
                if ($statement === $remarks_statement || $statement === $lng_modules_select_statement) {
                    return null;
                }
                return null;
            }
        );

        // ilCachedLanguage::readFromDB() loops fetchObject() - left unstubbed (defaults to null,
        // ending the loop immediately) is intentional: this test has nothing to assert about the
        // language cache's own content, only that it does not crash the call chain.
        $db->method('manipulate')->willReturn(0);
        $db->method('replace')->willReturn(1);

        $this->setGlobalVariable('ilDB', $db);

        return $db;
    }

    /**
     * The actual bugfix: a module with NO pre-existing lng_modules row (simulating the state right
     * after "delete" mode's raw DELETE wiped it, see this class' docblock) but WITH entries present in
     * the values being saved must still have its lng_modules row (re-)created via
     * ilObjLanguage::replaceLangModule() - not silently skipped.
     */
    public function testRecreatesTheLngModulesRowForAModulePresentInTheImport(): void
    {
        $lang_key = 'zz_delete_import_test';
        $this->seedEmptyGlobalLanguageFile($lang_key);
        $db = $this->mockDatabaseForSaveValues();

        $lng = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        $this->setGlobalVariable('lng', $lng);

        $db->expects($this->once())
            ->method('insert')
            ->with(
                'lng_modules',
                $this->callback(static function (array $values) use ($lang_key): bool {
                    if (($values['lang_key'][1] ?? null) !== $lang_key) {
                        return false;
                    }
                    if (($values['module'][1] ?? null) !== 'testmodule') {
                        return false;
                    }
                    $unserialized = unserialize($values['lang_array'][1] ?? '', ['allowed_classes' => false]);
                    return $unserialized === ['greeting' => 'Hallo, neu'];
                })
            )
            ->willReturn(1);

        ilObjLanguageExt::_saveValues(
            $lang_key,
            ['testmodule' . $lng->separator . 'greeting' => 'Hallo, neu'],
            []
        );
    }

    /**
     * Same scenario as above, but with two modules present in the imported values at once - each
     * module's own lng_modules row must be (re-)created independently, not just the first one visited
     * by the foreach loop over $save_array.
     */
    public function testRecreatesTheLngModulesRowForEveryModulePresentInTheImport(): void
    {
        $lang_key = 'zz_delete_import_test_multi';
        $this->seedEmptyGlobalLanguageFile($lang_key);
        $db = $this->mockDatabaseForSaveValues();

        $lng = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        $this->setGlobalVariable('lng', $lng);

        $inserted_modules = [];
        $db->expects($this->exactly(2))
            ->method('insert')
            ->with(
                'lng_modules',
                $this->callback(function (array $values) use (&$inserted_modules): bool {
                    $inserted_modules[] = $values['module'][1] ?? null;
                    return true;
                })
            )
            ->willReturn(1);

        ilObjLanguageExt::_saveValues(
            $lang_key,
            [
                'moduleone' . $lng->separator . 'greeting' => 'Hallo',
                'moduletwo' . $lng->separator . 'farewell' => 'Tschüss',
            ],
            []
        );

        sort($inserted_modules);
        $this->assertSame(['moduleone', 'moduletwo'], $inserted_modules);
    }
}
