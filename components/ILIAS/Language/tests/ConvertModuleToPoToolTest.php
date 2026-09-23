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
            [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', self::TOOL, ...$arguments],
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
}
