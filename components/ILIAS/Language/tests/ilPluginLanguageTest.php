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
 * ilPluginLanguage: getAvailableLangFiles()'s `.po`-over-`.lang` precedence, and updateLanguages()'s
 * new ability to read a plugin's shipped `ilias_<lang>.po` (msgctxt-less entries only, markup
 * sanitised, empty values skipped) alongside the legacy `.lang` format - written through the same
 * database-only write path as before (a plugin cannot contribute a LanguageFileDirectory yet, so no
 * overlay/artifact file is ever written for it).
 *
 * No test here touches a real database; ilDB is a recording stub, exactly like
 * SaveValuesRecreatesLngModulesAfterDeleteModeImportTest.php.
 */
class ilPluginLanguageTest extends ilLanguageBaseTestCase
{
    private ?string $plugin_root_directory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->plugin_root_directory) && is_dir($this->plugin_root_directory)) {
            MigratedPoFixture::removeDirectory($this->plugin_root_directory);
        }

        parent::tearDown();
    }

    private function createPluginInfo(string $module_prefix): ilPluginInfo
    {
        $this->plugin_root_directory = sys_get_temp_dir() . '/ilias_plugin_lang_test_' . bin2hex(random_bytes(4));
        mkdir($this->plugin_root_directory . '/lang', 0775, true);

        $component = $this->createStub(ilComponentInfo::class);
        $component->method('getId')->willReturn('comp');
        $slot = $this->createStub(ilPluginSlotInfo::class);
        $slot->method('getId')->willReturn('slot');

        $plugin_info = $this->createStub(ilPluginInfo::class);
        $plugin_info->method('getComponent')->willReturn($component);
        $plugin_info->method('getPluginSlot')->willReturn($slot);
        $plugin_info->method('getId')->willReturn($module_prefix);
        $plugin_info->method('getPath')->willReturn($this->plugin_root_directory);

        return $plugin_info;
    }

    /**
     * @param array<string, string> $entries identifier => value
     */
    private function writeShippedPluginPo(string $lang_key, array $entries): void
    {
        $catalog = new \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog();
        foreach ($entries as $identifier => $value) {
            $catalog->add(\MigratedPoFixture::entry(null, $identifier, $value));
        }
        \MigratedPoFixture::writePo($this->plugin_root_directory . '/lang/ilias_' . $lang_key . '.po', $catalog);
    }

    // ------------------------------------------------------------ getAvailableLangFiles()

    public function testGetAvailableLangFilesListsBothLangAndPoFiles(): void
    {
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        touch($this->plugin_root_directory . '/lang/ilias_de.lang');
        touch($this->plugin_root_directory . '/lang/ilias_en.po');

        $files = $plugin_language->getAvailableLangFiles();
        $by_key = array_combine(array_column($files, 'key'), array_column($files, 'file'));

        $this->assertSame('ilias_de.lang', $by_key['de']);
        $this->assertSame('ilias_en.po', $by_key['en']);
    }

    /**
     * A language shipped as both `.po` and `.lang` is read from the `.po`.
     */
    public function testPoWinsOverLangForTheSameLanguage(): void
    {
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        touch($this->plugin_root_directory . '/lang/ilias_de.lang');
        touch($this->plugin_root_directory . '/lang/ilias_de.po');

        $files = $plugin_language->getAvailableLangFiles();

        $this->assertCount(1, $files);
        $this->assertSame('ilias_de.po', $files[0]['file']);
    }

    // ------------------------------------------------------------ readPoFile() via updateLanguages()

    /**
     * Builds the ilDB double every updateLanguages() test needs: _getLocalChangesByModule()'s
     * queryF()/fetchAssoc() loop (no local changes, so the shipped value always wins) and
     * replaceLangEntry()'s replace() (captured for assertions). replaceLangModule() is additionally
     * exercised for real - its own self-check SELECT and the "collation" verification SELECT are
     * answered generically, mirroring SaveValuesRecreatesLngModulesAfterDeleteModeImportTest.php.
     *
     * @param array<string, string> $captured_replace_entries filled with "module#:#identifier" => value
     *        of every replaceLangEntry() call
     */
    private function stubDatabase(array &$captured_replace_entries): ilDBInterface
    {
        // replaceLangModule()'s own self-check SELECT (SQL "SELECT lang_array FROM lng_modules ...")
        // must unserialize() to an array or it treats the write as failed (mantis #20046/#19140);
        // _getLocalChangesByModule()'s SELECT ("SELECT identifier, value FROM lng_data ...") must
        // instead answer "no rows" (an empty while ($row = fetchAssoc()) loop) - two different SQL
        // texts through the same queryF(), told apart exactly like
        // SaveValuesRecreatesLngModulesAfterDeleteModeImportTest.php's mockDatabaseForSaveValues().
        $self_check_statement = $this->createStub(ilDBStatement::class);
        $no_rows_statement = $this->createStub(ilDBStatement::class);
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn($value, string $type = ''): string => "'" . (string) $value . "'");
        $db->method('queryF')->willReturnCallback(
            static fn(string $sql) => str_contains($sql, 'lng_modules') ? $self_check_statement : $no_rows_statement
        );
        $db->method('query')->willReturn($self_check_statement);
        $db->method('fetchAssoc')->willReturnCallback(
            static fn(ilDBStatement $statement): ?array => $statement === $self_check_statement
                ? ['lang_array' => serialize([])]
                : null
        );
        $db->method('manipulate')->willReturn(0);
        $db->method('insert')->willReturn(1);
        $db->method('replace')->willReturnCallback(
            function (string $table, array $keys, array $values) use (&$captured_replace_entries): int {
                $captured_replace_entries[$keys['module'][1] . '#:#' . $keys['identifier'][1]] = $values['value'][1];
                return 1;
            }
        );
        $this->setGlobalVariable('ilDB', $db);

        return $db;
    }

    /**
     * @param list<string> $warnings
     */
    private function stubLoggerCapturingWarnings(array &$warnings): void
    {
        $logger = $this->createStub(ilLogger::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);
    }

    /**
     * A plain, valid shipped `.po` is written through the database-only path, with the plugin's
     * prefix - never any overlay/artifact file (no LanguageFileDirectory is contributed for a plugin).
     */
    public function testUpdateLanguagesWritesPoValuesWithThePrefixThroughTheDatabaseOnly(): void
    {
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        $this->writeShippedPluginPo('de', ['greeting' => 'Hallo']);
        $captured = [];
        $this->stubDatabase($captured);

        $plugin_language->updateLanguages(['de']);

        // getPrefix() = "comp_slot_ptest"; the identifier is "<prefix>_<key>" (see updateLanguages()).
        // No LanguageFileDirectoryManager is registered in $DIC at all - a plugin cannot contribute
        // one yet - so replaceLangModule()'s overlay sync is a guaranteed no-op (see its own
        // findDirectory()); nothing beyond the plain database write below is exercised or needed.
        $this->assertSame('Hallo', $captured['comp_slot_ptest#:#comp_slot_ptest_greeting']);
    }

    /**
     * An entry WITH a msgctxt - even an empty one - belongs to no module a plugin `.po` may declare
     * (a plugin `.po` has exactly one, implicit, module) and is ignored, with a warning; every other,
     * contextless entry is still applied.
     */
    public function testEntriesWithAMsgctxtAreIgnoredAndWarnedAbout(): void
    {
        $context = 'some_module';
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        $catalog = new \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog();
        $catalog->add(\MigratedPoFixture::entry($context, 'foreign', 'Fremd'));
        $catalog->add(\MigratedPoFixture::entry(null, 'greeting', 'Hallo'));
        \MigratedPoFixture::writePo($this->plugin_root_directory . '/lang/ilias_de.po', $catalog);
        $captured = [];
        $this->stubDatabase($captured);
        $warnings = [];
        $this->stubLoggerCapturingWarnings($warnings);

        $plugin_language->updateLanguages(['de']);

        $this->assertArrayNotHasKey('comp_slot_ptest#:#comp_slot_ptest_foreign', $captured);
        $this->assertSame('Hallo', $captured['comp_slot_ptest#:#comp_slot_ptest_greeting']);
        $this->assertNotEmpty(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'msgctxt') && str_contains($w, 'foreign')));
    }

    /**
     * A value with markup TranslationMarkupPolicy does not allow is cleaned (not rejected) - like the
     * build compiles a component's shipped `.po` - and the cleaning is warned about.
     */
    public function testDisallowedMarkupIsSanitisedAndWarnedAboutNotRejected(): void
    {
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        $this->writeShippedPluginPo('de', ['greeting' => '<script>alert(1)</script>Hallo']);
        $captured = [];
        $this->stubDatabase($captured);
        $warnings = [];
        $this->stubLoggerCapturingWarnings($warnings);

        $plugin_language->updateLanguages(['de']);

        $value = $captured['comp_slot_ptest#:#comp_slot_ptest_greeting'];
        $this->assertStringNotContainsString('<script', $value);
        $this->assertStringContainsString('Hallo', $value);
        $this->assertNotEmpty(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'Markup not allowed')));
    }

    /**
     * readLangFile() (the legacy `.lang` format) now goes through the same cleanShippedValues() as
     * readPoFile(): a value with markup TranslationMarkupPolicy does not allow is sanitised, not
     * rejected, and the cleaning is warned about.
     */
    public function testDisallowedMarkupInALangFileIsSanitisedAndWarnedAboutNotRejected(): void
    {
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        file_put_contents(
            $this->plugin_root_directory . '/lang/ilias_de.lang',
            "greeting#:#<script>alert(1)</script>Hallo\n"
        );
        $captured = [];
        $this->stubDatabase($captured);
        $warnings = [];
        $this->stubLoggerCapturingWarnings($warnings);

        $plugin_language->updateLanguages(['de']);

        $value = $captured['comp_slot_ptest#:#comp_slot_ptest_greeting'];
        $this->assertStringNotContainsString('<script', $value);
        $this->assertStringContainsString('Hallo', $value);
        $this->assertNotEmpty(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'Markup not allowed')));
    }

    /**
     * An entry with an empty translation is skipped entirely - it is not written as an empty value.
     */
    public function testEmptyTranslationsAreSkipped(): void
    {
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        $this->writeShippedPluginPo('de', ['greeting' => 'Hallo', 'empty_one' => '']);
        $captured = [];
        $this->stubDatabase($captured);

        $plugin_language->updateLanguages(['de']);

        $this->assertArrayHasKey('comp_slot_ptest#:#comp_slot_ptest_greeting', $captured);
        $this->assertArrayNotHasKey('comp_slot_ptest#:#comp_slot_ptest_empty_one', $captured);
    }

    /**
     * A broken `.po` skips ONLY that language (logged) - other, valid languages of the same plugin
     * are still updated.
     */
    public function testABrokenPoSkipsOnlyThatLanguageAndOthersAreStillUpdated(): void
    {
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest'));
        file_put_contents($this->plugin_root_directory . '/lang/ilias_de.po', "msgid \"kaputt\n");
        $this->writeShippedPluginPo('en', ['greeting' => 'Hello']);
        $captured = [];
        $this->stubDatabase($captured);
        $warnings = [];
        $this->stubLoggerCapturingWarnings($warnings);

        // both languages processed in one call - "de" (broken) must not stop "en" from being reached
        $plugin_language->updateLanguages(['de', 'en']);

        $this->assertSame('Hello', $captured['comp_slot_ptest#:#comp_slot_ptest_greeting'], 'the valid "en" .po is still applied');
        $this->assertNotEmpty(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'cannot be read')));
    }
}
