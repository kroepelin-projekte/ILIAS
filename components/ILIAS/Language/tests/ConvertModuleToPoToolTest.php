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
        $catalog = \ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog::fromPoFile($root . '/out/tst_de.po');
        $entry = $catalog->find('tst', 'greeting');
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
        $catalog = \ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog::fromPoFile($root . '/out/tst_de.po');
        $entry = $catalog->find('tst', 'greeting');
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
        $catalog = \ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog::fromPoFile($root . '/out/tst_fr.po');
        $entry = $catalog->find('tst', 'greeting');
        $this->assertNotNull($entry);
        $this->assertTrue($entry->hasFlag('fuzzy'));
        $this->assertSame([], $entry->getExtractedComments());
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
        $catalog = \ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog::fromPoFile($root . '/out/tst_de.po');
        $this->assertSame('Hallo', $catalog->find('tst', 'greeting')?->getTranslation());
    }
}
