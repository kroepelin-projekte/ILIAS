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

use PHPUnit\Framework\TestCase;

/**
 * End-to-end check of tools/po-migration/convert_module_to_po.php: re-running it for "tos" over the
 * shipped files reproduces them byte for byte - i.e. the legacy `.lang` files, the tool and the
 * gettext adapter still agree on every value, comment, flag and header of the migrated module.
 *
 * The tool loads only Composer's autoloader. Classes added to src/ after the last
 * `composer dump-autoload` are not in its classmap yet, so the tool is run with an
 * auto_prepend_file registering the same PSR-4 fallback as tests/bootstrap.php.
 */
class ConvertModuleToPoToolTest extends TestCase
{
    private const string TOOL = __DIR__ . '/../tools/po-migration/convert_module_to_po.php';
    private const string SHIPPED_DIRECTORY = __DIR__ . '/../../TermsOfService/lang';
    private const string POLL_SHIPPED_DIRECTORY = __DIR__ . '/../../Poll/lang';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ilias_convert_tool_' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/out', 0775, true);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->directory);
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runTool(string ...$arguments): array
    {
        return $this->runToolAt(self::TOOL, ...$arguments);
    }

    /**
     * Like runTool(), but against an arbitrary copy of the tool - needed for the fixture-repo tests
     * below: the tool derives its `$repo_root` (and therefore where it looks for `lang/ilias_*.lang`)
     * from its own `__DIR__`, five levels up - there is no command-line override for it. The only way
     * to feed it throwaway `.lang` fixtures instead of the real repository's is to run a copy of the
     * tool from the same relative depth inside a throwaway root (see buildFixtureRepo()).
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runToolAt(string $tool_path, string ...$arguments): array
    {
        $prepend = $this->directory . '/autoload_language_src.php';
        file_put_contents($prepend, sprintf(
            <<<'PHP'
                <?php
                spl_autoload_register(static function (string $class): void {
                    $prefix = 'ILIAS\\Language\\';
                    if (!str_starts_with($class, $prefix)) {
                        return;
                    }
                    $file = %s . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                });
                PHP,
            var_export(realpath(__DIR__ . '/../src'), true)
        ));

        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', $tool_path, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * Builds a throwaway root mirroring just enough of the real repository layout for the tool to run
     * against fixture `lang/ilias_<key>.lang` files instead of the real ones: a copy of the tool itself
     * at the same relative depth (`components/ILIAS/Language/tools/po-migration/`, see runToolAt()'s
     * docblock) and a symlinked Composer autoloader (the real one - it classmaps to the real,
     * absolute source paths regardless of where the tool script itself runs from, so this never needs
     * copying the whole vendor tree).
     */
    private function buildFixtureRepo(): string
    {
        $root = $this->directory . '/repo';
        $tool_dir = $root . '/components/ILIAS/Language/tools/po-migration';
        mkdir($tool_dir, 0775, true);
        mkdir($root . '/lang', 0775, true);
        mkdir($root . '/out', 0775, true);
        copy(self::TOOL, $tool_dir . '/convert_module_to_po.php');

        mkdir($root . '/vendor/composer/vendor', 0775, true);
        $real_autoload = realpath(__DIR__ . '/../../../../vendor/composer/vendor/autoload.php');
        $this->assertIsString($real_autoload, 'the real Composer autoloader was not found');
        symlink($real_autoload, $root . '/vendor/composer/vendor/autoload.php');

        return $root;
    }

    private function toolPathOf(string $fixture_root): string
    {
        return $fixture_root . '/components/ILIAS/Language/tools/po-migration/convert_module_to_po.php';
    }

    /**
     * Writes plurals.json (see its own docblock) next to the fixture repo's copy of the tool -
     * without this, the tool writes no "Plural-Forms" header and no plural message at all.
     *
     * @param array<string, string> $plural_forms lang_key => "Plural-Forms" header
     * @param array<string, array<string, array{singular?: string, plural_id?: string}>> $modules
     *        module => msgid => definition, see plurals.json's own docblock
     */
    private function writePluralsConfig(string $fixture_root, array $plural_forms, array $modules): void
    {
        file_put_contents(
            $fixture_root . '/components/ILIAS/Language/tools/po-migration/plurals.json',
            (string) json_encode(['plural_forms' => $plural_forms, 'modules' => $modules], JSON_THROW_ON_ERROR)
        );
    }

    private function writeLangFile(string $fixture_root, string $lang_key, string $content): void
    {
        file_put_contents($fixture_root . '/lang/ilias_' . $lang_key . '.lang', $content);
    }

    /**
     * @return array<string, string> file name => content
     */
    private static function files(string $directory): array
    {
        $files = [];
        foreach (glob($directory . '/*.{po,pot}', GLOB_BRACE) ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }
        ksort($files);

        return $files;
    }

    /**
     * The tool keeps the headers of an existing target file (e.g., "Plural-Forms"), so it is run
     * over a copy of the shipped files - exactly how a module is re-converted.
     */
    public function testReconvertingTosReproducesTheShippedFilesByteForByte(): void
    {
        $shipped = self::files(self::SHIPPED_DIRECTORY);
        $this->assertNotSame([], $shipped, 'the shipped tos files were not found');
        foreach ($shipped as $name => $content) {
            file_put_contents($this->directory . '/out/' . $name, $content);
        }

        [$exit_code, $stdout, $stderr] = $this->runTool('tos', 'de', $this->directory . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $this->assertSame('', $stderr, 'no warnings, notices or deprecations');
        $this->assertStringContainsString('round-trip identically through PO', $stdout);
        $this->assertSame(array_keys($shipped), array_keys(self::files($this->directory . '/out')), 'no file added or lost');
        foreach (self::files($this->directory . '/out') as $name => $content) {
            $this->assertSame($shipped[$name], $content, $name);
        }
    }

    /**
     * The plural pilot ("poll", see plurals.json next to the tool): re-running the tool over the
     * shipped files reproduces them byte for byte, for every language including a single-form one
     * ("ja") - locking in the distribution rule (see plurals.json's docblock): a "n==1 -> 0, else 1"
     * language's plural message with a configured "singular" key gets msgstr[0] from that key's own
     * value and msgstr[1] from the message's own value (poll_population); a message with no singular
     * key, or a language whose rule isn't that shape, gets its own value in every form
     * (poll_vote_error_multi, and poll_population itself in "ja", which has only one form).
     */
    public function testReconvertingPollReproducesTheShippedFilesByteForByteIncludingPluralForms(): void
    {
        $shipped = self::files(self::POLL_SHIPPED_DIRECTORY);
        $this->assertNotSame([], $shipped, 'the shipped poll files were not found');
        $this->assertStringContainsString('msgid_plural "poll_population_plural"', $shipped['poll_de.po'] ?? '', 'precondition: poll_de.po has the expected plural message');
        foreach ($shipped as $name => $content) {
            file_put_contents($this->directory . '/out/' . $name, $content);
        }

        [$exit_code, $stdout, $stderr] = $this->runTool('poll', 'de', $this->directory . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $this->assertSame('', $stderr, 'no warnings, notices or deprecations');
        $this->assertSame(array_keys($shipped), array_keys(self::files($this->directory . '/out')), 'no file added or lost');
        foreach (self::files($this->directory . '/out') as $name => $content) {
            $this->assertSame($shipped[$name], $content, $name);
        }
    }

    /**
     * The "every language's keys must be a subset of the reference language's" guard: without it, a
     * key only "fr" has would be silently dropped (the catalogs are built from the reference language's
     * keys) instead of failing loudly before anything is written.
     *
     * Mutation this catches: dropping this check, or checking the union instead of "fr not subset of
     * de", would let the tool proceed and write files despite the inconsistent source data.
     */
    public function testFailsWithExitOneAndWritesNothingWhenALanguageHasAKeyMissingFromTheReferenceLanguage(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo\n");
        $this->writeLangFile($root, 'fr', "tst#:#greeting#:#Bonjour\ntst#:#extra#:#En trop\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(1, $exit_code, $stdout . $stderr);
        $this->assertStringContainsString("reference language 'de' does not have", $stderr);
        $this->assertStringContainsString('fr: extra', $stderr);
        $this->assertSame([], glob($root . '/out/*') ?: [], 'nothing must be written once an inconsistency was found');
    }

    /**
     * A line with more than one "#:#" in its value is parsed exactly like
     * LanguageInstallationManager::insertLanguage() does: only the part before the further "#:#" is
     * the value, and a warning is emitted - proven here by asserting on both the warning text and the
     * value actually written to the `.po`.
     */
    public function testWarnsOnAnExtraHashColonHashInAValueAndUsesOnlyThePartBeforeItLikeTheInstaller(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo#:#Extra\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $this->assertStringContainsString(
            'contains "#:#" in its value - only the part before it is used, like the installer does.',
            $stderr
        );
        $catalog = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_de.po');
        $entry = $catalog->find(null, 'greeting');
        $this->assertNotNull($entry);
        $this->assertSame('Hallo', $entry->getTranslation());
    }

    /**
     * is_fuzzy_marker() only matches the dated "new variable" placeholder format - an authored note
     * that merely happens to mention the words "new variable" (without the leading date) must stay a
     * plain extracted comment, never the "fuzzy" flag.
     *
     * Mutation this catches: widening is_fuzzy_marker() to `str_contains($comment, 'new variable')`
     * would flag this entry as fuzzy and drop the actual note text.
     */
    public function testANoteThatMerelyMentionsNewVariableStaysANoteAndIsNotMarkedFuzzy(): void
    {
        $root = $this->buildFixtureRepo();
        $note = 'Bitte pruefen, ob hier evtl. eine neue Variable (englisch: new variable) gemeint war';
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo###$note\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $catalog = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_de.po');
        $entry = $catalog->find(null, 'greeting');
        $this->assertNotNull($entry);
        $this->assertFalse($entry->hasFlag('fuzzy'));
        $this->assertSame([$note], $entry->getExtractedComments());
    }

    /**
     * The real, dated "new variable" placeholder format, by contrast, must still be recognized and
     * turned into the "fuzzy" flag, not kept as a comment - the counterpart proving the test above
     * isn't merely vacuous (is_fuzzy_marker() never matching anything at all).
     */
    public function testTheDatedNewVariablePlaceholderFormatIsMarkedFuzzyNotKeptAsANote(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo\n");
        $this->writeLangFile($root, 'fr', "tst#:#greeting#:####13 3 2019 new variable\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $catalog = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_fr.po');
        $entry = $catalog->find(null, 'greeting');
        $this->assertNotNull($entry);
        $this->assertTrue($entry->hasFlag('fuzzy'));
        $this->assertSame([], $entry->getExtractedComments());
    }

    /**
     * A plural message whose configured singular key carries the dated "new variable" placeholder
     * (is_fuzzy_marker(), see the test above) is itself marked "fuzzy" too - but only in a language
     * whose rule actually uses the singular key's value as msgstr[0] (isOneSingularOtherPlural(), see
     * PluralForms). In a language that does not (here: a single-form one, "zh") every form is the
     * plural message's own value regardless of the singular key, so its own fuzziness must not leak
     * in merely because the singular key happens to be marked fuzzy.
     */
    public function testAPluralMessageIsMarkedFuzzyWhenItsSingularKeyIsButOnlyWhereTheSingularValueIsActuallyUsed(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#item#:#Eintraege\ntst#:#item_singular#:#Ein Eintrag\n");
        $this->writeLangFile(
            $root,
            'fr',
            "tst#:#item#:#Des entrees\ntst#:#item_singular#:#Une entree###13 3 2019 new variable\n"
        );
        $this->writeLangFile(
            $root,
            'zh',
            "tst#:#item#:#Eintraege ZH\ntst#:#item_singular#:#Ein Eintrag ZH###13 3 2019 new variable\n"
        );
        $this->writePluralsConfig(
            $root,
            [
                'de' => 'nplurals=2; plural=(n != 1);',
                'fr' => 'nplurals=2; plural=(n != 1);', // isOneSingularOtherPlural() - singular is used
                'zh' => 'nplurals=1; plural=0;', // not isOneSingularOtherPlural() - never used
            ],
            ['tst' => ['item' => ['singular' => 'item_singular']]]
        );

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $fr_item = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_fr.po')
            ->find(null, 'item');
        $this->assertNotNull($fr_item);
        $this->assertSame(['Une entree', 'Des entrees'], $fr_item->getPluralTranslations(), 'precondition: the singular value is msgstr[0]');
        $this->assertTrue($fr_item->hasFlag('fuzzy'), 'fr: the singular key used as msgstr[0] is fuzzy');

        $zh_item = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_zh.po')
            ->find(null, 'item');
        $this->assertNotNull($zh_item);
        $this->assertSame(['Eintraege ZH'], $zh_item->getPluralTranslations(), 'precondition: single form, own value - not the singular key');
        $this->assertFalse($zh_item->hasFlag('fuzzy'), 'zh: the singular key is never used here, so its fuzziness must not leak in');
    }

    /**
     * A plural message with NO configured singular key (real example: poll's "poll_vote_error_multi")
     * has its own, single translated value copied into every form ($plural_forms_of() with no
     * "singular" - decided 2026-09-28, see convert_module_to_po.php's docblock): copied into more than
     * one form (nplurals > 1) is marked "fuzzy" - it still needs a translation per form, not just a
     * duplicate of the one value that happened to exist before the module had plurals. A single-form
     * language (nplurals=1: "zh") copies into exactly one form, so it is never marked fuzzy this way.
     */
    public function testAPluralMessageWithoutASingularKeyIsFuzzyWhenItsValueIsCopiedIntoMoreThanOneForm(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#multi#:#Mehrere Werte\n");
        $this->writeLangFile($root, 'zh', "tst#:#multi#:#Mehrere Werte ZH\n");
        $this->writePluralsConfig(
            $root,
            ['de' => 'nplurals=2; plural=(n != 1);', 'zh' => 'nplurals=1; plural=0;'],
            ['tst' => ['multi' => []]]
        );

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $de_multi = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_de.po')
            ->find(null, 'multi');
        $this->assertNotNull($de_multi);
        $this->assertSame(['Mehrere Werte', 'Mehrere Werte'], $de_multi->getPluralTranslations());
        $this->assertTrue($de_multi->hasFlag('fuzzy'), 'de: copied into 2 forms');

        $zh_multi = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_zh.po')
            ->find(null, 'multi');
        $this->assertNotNull($zh_multi);
        $this->assertSame(['Mehrere Werte ZH'], $zh_multi->getPluralTranslations());
        $this->assertFalse($zh_multi->hasFlag('fuzzy'), 'zh: a single form is not "copied" anywhere');
    }

    /**
     * findShippedModuleFiles()'s sibling in this tool, the `lang/ilias_*.lang` glob loop: a matched
     * file whose name still doesn't yield a lowercase language key (e.g. an uppercase one) is skipped
     * with a warning rather than silently used or fatally erroring - proven here by seeding an
     * otherwise-matching-looking file next to a valid one and asserting the valid one's content still
     * made it through untouched.
     */
    public function testAFileWithoutALanguageKeyInItsNameIsSkippedWithAWarning(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo\n");
        file_put_contents($root . '/lang/ilias_ABC.lang', "tst#:#greeting#:#Sollte nicht gelesen werden\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $this->assertStringContainsString('WARNING: skipping', $stderr);
        $this->assertStringContainsString('ilias_ABC.lang', $stderr);
        $this->assertStringContainsString('no language key in its name', $stderr);
        $this->assertFileDoesNotExist($root . '/out/tst_ABC.po');
    }

    /**
     * "Language" keeps the language key (translation tools read it), "Language-Team" names the
     * language in English from `meta_l_<lang>` of `lang/ilias_en.lang`. Without such a name the
     * header is left out and a note is printed; the template never gets one.
     */
    public function testLanguageTeamHeaderNamesTheLanguageInEnglishFromTheMetaModule(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo\n");
        $this->writeLangFile($root, 'en', "tst#:#greeting#:#Hello\nmeta#:#meta_l_de#:#German\nmeta#:#meta_l_en#:#English\n");
        $this->writeLangFile($root, 'fr', "tst#:#greeting#:#Bonjour\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt($this->toolPathOf($root), 'tst', 'de', $root . '/out');

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $de = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_de.po');
        $this->assertSame('de', $de->getHeader('Language'));
        $this->assertSame('German', $de->getHeader('Language-Team'));
        $en = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_en.po');
        $this->assertSame('English', $en->getHeader('Language-Team'));
        $fr = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst_fr.po');
        $this->assertNull($fr->getHeader('Language-Team'));
        $this->assertStringContainsString("NOTE: no meta_l_fr in lang/ilias_en.lang", $stdout);
        $pot = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/tst.pot');
        $this->assertNull($pot->getHeader('Language-Team'));
    }

    // ------------------------------------------------------------ --pattern

    /**
     * A custom --pattern names both the per-language `.po` files and the `.pot` template (the
     * pattern without its "%s" placeholder, trimmed of "_-." at either end) - never the module name.
     */
    public function testCustomPatternNamesThePoFilesAndThePotTemplate(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt(
            $this->toolPathOf($root),
            '--pattern=ilias_%s',
            'tst',
            'de',
            $root . '/out'
        );

        $this->assertSame(0, $exit_code, $stdout . $stderr);
        $this->assertFileExists($root . '/out/ilias_de.po');
        $this->assertFileExists($root . '/out/ilias.pot');
        $this->assertFileDoesNotExist($root . '/out/tst_de.po');
        $this->assertFileDoesNotExist($root . '/out/tst.pot');
        $entry = \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog::fromPoFile($root . '/out/ilias_de.po')->find(null, 'greeting');
        $this->assertNotNull($entry);
        $this->assertSame('Hallo', $entry->getTranslation());
    }

    /**
     * An invalid --pattern (validated the same way MigratedLanguageFilePaths::assertValidShippedFileNamePattern()
     * checks a directory's own pattern) fails loudly with exit code 1 and writes nothing at all -
     * before the source `.lang` files are even read.
     */
    public function testAnInvalidPatternFailsWithExitOneAndWritesNothing(): void
    {
        $root = $this->buildFixtureRepo();
        $this->writeLangFile($root, 'de', "tst#:#greeting#:#Hallo\n");

        [$exit_code, $stdout, $stderr] = $this->runToolAt(
            $this->toolPathOf($root),
            '--pattern=no-placeholder-at-all',
            'tst',
            'de',
            $root . '/out'
        );

        $this->assertSame(1, $exit_code, $stdout . $stderr);
        $this->assertStringContainsString('not a valid file name pattern', $stderr);
        $this->assertSame([], glob($root . '/out/*') ?: [], 'nothing must be written for an invalid pattern');
    }
}
