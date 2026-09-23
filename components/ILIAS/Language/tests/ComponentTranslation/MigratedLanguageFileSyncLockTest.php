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
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use PHPUnit\Framework\TestCase;

/**
 * MigratedLanguageFileSync::withOverlayLock(): mutual exclusion and the re-entrancy guard
 * ($held_locks). Every scenario that would deadlock a broken implementation runs a real lock probe
 * or the risky call itself in a throwaway child PHP process with a hard wall-clock timeout, so a
 * regression fails the assertion instead of hanging the test run forever.
 */
class MigratedLanguageFileSyncLockTest extends TestCase
{
    private const string MODULE = 'stest';

    private string $client_data_dir;
    private LanguageFileDirectory $directory;
    private LanguageFileDirectoryManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../../'));
        }

        $this->client_data_dir = sys_get_temp_dir() . '/ilias_mlfs_lock_test_' . bin2hex(random_bytes(4));
        mkdir($this->client_data_dir, 0775, true);
        $this->directory = MigratedPoFixture::directory(self::MODULE, 'x/');
        $this->manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $this->directory);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->client_data_dir);

        parent::tearDown();
    }

    private function lockFile(): string
    {
        return MigratedLanguageFilePaths::overlayBasePath($this->client_data_dir, $this->directory, 'de') . '.lock';
    }

    /**
     * Forces withOverlayLock() to take a real lock without needing a shipped `.po`: the overlay
     * directory already existing is enough (see withOverlayLock()'s early-return condition).
     */
    private function seedLockDirectory(): void
    {
        mkdir(dirname($this->lockFile()), 0775, true);
    }

    /**
     * Runs $code as a standalone PHP process and waits at most $timeout_seconds for it to finish -
     * killing it otherwise. Used whenever the scenario under test could, if a regression
     * reintroduces a self-deadlock, block forever: that must fail this test instead of hanging the
     * whole suite.
     *
     * @return array{finished: bool, exit_code: ?int, output: string}
     */
    private function runPhpWithTimeout(string $code, float $timeout_seconds): array
    {
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $this->fail('Could not start the child PHP process.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeout_seconds;
        $output = '';
        do {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['finished' => true, 'exit_code' => $status['exitcode'], 'output' => $output];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        proc_terminate($process, 9);
        usleep(100_000);
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['finished' => false, 'exit_code' => null, 'output' => $output];
    }

    /**
     * Probes $lock_file with a non-blocking exclusive flock() from a separate process (never blocks,
     * so no timeout guard is needed): 0 = the lock was free (acquired and released immediately),
     * 1 = held by someone else, 2 = the file could not even be opened.
     */
    private function probeLock(string $lock_file): int
    {
        // Mirrors acquireLock()'s own 'c' ?: 'r' fallback: a lock file that is only readable to this
        // process (see the read-only-fallback test) must not make the probe report "could not even
        // open it" (2) when the real question is only whether the lock itself is held (1) or free (0).
        $code = sprintf(
            '$h = @fopen(%1$s, "c") ?: @fopen(%1$s, "r"); if ($h === false) { exit(2); } exit(flock($h, LOCK_EX | LOCK_NB) ? 0 : 1);',
            var_export($lock_file, true)
        );
        $result = $this->runPhpWithTimeout($code, 3.0);
        $this->assertTrue($result['finished'], 'the non-blocking lock probe itself must never hang: ' . $result['output']);

        return (int) $result['exit_code'];
    }

    private function nestedLockChildCode(): string
    {
        $template = <<<'PHP'
            use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
            use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
            use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
            use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;

            $root = __ROOT__;
            require_once $root . '/vendor/composer/vendor/autoload.php';
            spl_autoload_register(static function (string $class) use ($root): void {
                $prefix = 'ILIAS\\Language\\';
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $file = $root . '/components/ILIAS/Language/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            });

            $directory = new class implements LanguageFileDirectory {
                public function getPrefix(): string
                {
                    return 'stest';
                }
                public function getPath(): string
                {
                    return 'x/';
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
            $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $directory);
            $client_data_dir = __CLIENT_DATA_DIR__;

            $log = [];
            $result = MigratedLanguageFileSync::withOverlayLock(
                $manager,
                'de',
                'stest',
                $client_data_dir,
                function () use ($manager, $client_data_dir, &$log) {
                    $log['outer'] = 1;

                    return MigratedLanguageFileSync::withOverlayLock(
                        $manager,
                        'de',
                        'stest',
                        $client_data_dir,
                        function () use (&$log) {
                            $log['inner'] = 1;

                            return 'nested-return';
                        }
                    );
                }
            );
            $log['result'] = $result;
            foreach ($log as $key => $value) {
                echo $key . ':' . $value . "\n";
            }
            PHP;

        return str_replace(
            ['__ROOT__', '__CLIENT_DATA_DIR__'],
            [var_export(ILIAS_ABSOLUTE_PATH, true), var_export($this->client_data_dir, true)],
            $template
        );
    }

    // ------------------------------------------------------------- re-entrancy

    /**
     * Mutation: removing/weakening the self::$held_locks re-entrancy guard. Without it, the nested
     * call opens a SECOND file handle on the very same, already-locked file and blocks on
     * flock(LOCK_EX) forever (a well-known same-process self-deadlock, since flock() is scoped to
     * the open file description, not the process) - so this runs in a child process with a hard
     * timeout instead of risking a hung test run.
     */
    public function testANestedWithOverlayLockForTheSameModuleReturnsInsteadOfDeadlocking(): void
    {
        $this->seedLockDirectory();

        $result = $this->runPhpWithTimeout($this->nestedLockChildCode(), 5.0);

        $this->assertTrue(
            $result['finished'],
            'a nested withOverlayLock() call for the same module must return instead of hanging: ' . $result['output']
        );
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('outer:1', $result['output']);
        $this->assertStringContainsString('inner:1', $result['output']);
        $this->assertStringContainsString('result:nested-return', $result['output']);
    }

    // ------------------------------------------------------- mutual exclusion

    /**
     * Mutation: withOverlayLock() not actually holding flock(LOCK_EX) for the duration of $callback,
     * or releasing it too early - a concurrent, independent process must be refused the lock while
     * the callback runs and must succeed the moment it returns.
     */
    public function testAConcurrentProcessCannotAcquireTheLockWhileTheCallbackRunsButCanAfterwards(): void
    {
        $this->seedLockDirectory();
        $lock_file = $this->lockFile();

        $probe_during = null;
        MigratedLanguageFileSync::withOverlayLock(
            $this->manager,
            'de',
            self::MODULE,
            $this->client_data_dir,
            function () use (&$probe_during, $lock_file): void {
                $probe_during = $this->probeLock($lock_file);
            }
        );
        $probe_after = $this->probeLock($lock_file);

        $this->assertSame(1, $probe_during, 'a concurrent process must not be able to lock the file while the callback runs');
        $this->assertSame(0, $probe_after, 'the lock must be released once the callback returns');
    }

    /**
     * Mutation: the `finally` release being removed or reordered - an exception in the callback must
     * not leave the lock held forever.
     */
    public function testTheLockIsReleasedAfterAnExceptionInTheCallback(): void
    {
        $this->seedLockDirectory();
        $lock_file = $this->lockFile();

        try {
            MigratedLanguageFileSync::withOverlayLock(
                $this->manager,
                'de',
                self::MODULE,
                $this->client_data_dir,
                static function (): void {
                    throw new RuntimeException('boom');
                }
            );
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $this->probeLock($lock_file), 'the lock must be released even after an exception in the callback');
    }

    // -------------------------------------------------------- read-only fallback

    /**
     * Mutation: removing the `?: @fopen($lock_file, 'r')` fallback in acquireLock() - a lock file
     * that cannot be opened for writing (e.g. created by another user) must still be lockable via a
     * read-only handle. Asserted via the same concurrent-probe technique as above: only a REAL,
     * exclusive lock refuses the concurrent probe - a regression that instead falls through to
     * running $callback unlocked would let the probe through, too.
     */
    public function testAcquiresARealLockViaTheReadOnlyFallbackWhenTheLockFileCannotBeOpenedForWriting(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root can always open the lock file for writing; the read-only fallback cannot be exercised.');
        }
        $lock_file = $this->lockFile();
        mkdir(dirname($lock_file), 0775, true);
        touch($lock_file);
        chmod($lock_file, 0444);

        try {
            $probe_during = null;
            $ran = false;
            MigratedLanguageFileSync::withOverlayLock(
                $this->manager,
                'de',
                self::MODULE,
                $this->client_data_dir,
                function () use (&$probe_during, &$ran, $lock_file): void {
                    $ran = true;
                    $probe_during = $this->probeLock($lock_file);
                }
            );

            $this->assertTrue($ran, 'the callback must still run under the read-only-fallback lock');
            $this->assertSame(1, $probe_during, 'the read-only fallback must still take a real, exclusive lock');
        } finally {
            chmod($lock_file, 0664);
        }
    }

    // ---------------------------------------------------------------- no lock

    /**
     * Mutation: withOverlayLock() attempting to acquire a lock even without a manager - it must run
     * $callback directly and touch nothing on disk.
     */
    public function testRunsWithoutALockOrDirectoryWhenTheManagerIsNull(): void
    {
        $ran = false;
        $result = MigratedLanguageFileSync::withOverlayLock(
            null,
            'de',
            self::MODULE,
            $this->client_data_dir,
            static function () use (&$ran): string {
                $ran = true;

                return 'ok';
            }
        );

        $this->assertTrue($ran);
        $this->assertSame('ok', $result);
        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
    }

    /**
     * Mutation: findDirectory() being bypassed - a module without a contributed directory must run
     * unlocked, too.
     */
    public function testRunsWithoutALockWhenTheModuleHasNoContributedDirectory(): void
    {
        $ran = false;
        MigratedLanguageFileSync::withOverlayLock(
            $this->manager,
            'de',
            'unknown_module',
            $this->client_data_dir,
            static function () use (&$ran): void {
                $ran = true;
            }
        );

        $this->assertTrue($ran);
        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
    }

    /**
     * Mutation: the "overlay directory missing AND not shipped" guard being weakened - a module with
     * a contributed directory, but neither an existing overlay directory nor a shipped `.po`, must
     * still run unlocked and create nothing.
     */
    public function testRunsWithoutALockWhenTheModuleIsNotShippedAndHasNoExistingOverlayDirectory(): void
    {
        $ran = false;
        MigratedLanguageFileSync::withOverlayLock(
            $this->manager,
            'de',
            self::MODULE,
            $this->client_data_dir,
            static function () use (&$ran): void {
                $ran = true;
            },
            ILIAS_ABSOLUTE_PATH
        );

        $this->assertTrue($ran);
        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
    }
}
