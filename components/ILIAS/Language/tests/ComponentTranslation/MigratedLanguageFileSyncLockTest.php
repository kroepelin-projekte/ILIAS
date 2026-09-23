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

    // ------------------------------------------------------- the .lock file itself

    /**
     * Mutation: removeOverlay()'s early guard (`!is_file(po) && !is_file(mo) && !is_file(lock)`)
     * losing the `.lock` branch - the lock file left over from an interrupted run must still be
     * removed even when the `.po`/`.mo` pair is completely gone.
     */
    public function testRemoveOverlayRunsAndRemovesTheLockFileWhenOnlyTheLockFileIsLeft(): void
    {
        $overlay_base = MigratedLanguageFilePaths::overlayBasePath($this->client_data_dir, $this->directory, 'de');
        mkdir(dirname($overlay_base), 0775, true);
        file_put_contents($overlay_base . '.lock', '');

        MigratedLanguageFileSync::removeOverlay($this->manager, 'de', self::MODULE, $this->client_data_dir);

        $this->assertFileDoesNotExist($overlay_base . '.lock');
    }

    /**
     * Mutation: lockAndRun() deleting the lock file even for a NESTED removeOverlay() call (one whose
     * lock is already held by an enclosing withOverlayLock()) - see lockAndRun()'s
     * `isset(self::$held_locks[...])` shortcut, which returns $callback() directly and must never reach
     * the "acquire a fresh lock, then delete it" branch. The nested call must still actually remove the
     * overlay `.po`/`.mo` (it is not a no-op), and must not retry/replace the lock file either - it stays
     * the exact same inode throughout.
     */
    public function testANestedRemoveOverlayInsideWithOverlayLockDoesNotDeleteTheLockFileOrRetry(): void
    {
        $overlay_base = MigratedLanguageFilePaths::overlayBasePath($this->client_data_dir, $this->directory, 'de');
        mkdir(dirname($overlay_base), 0775, true);
        file_put_contents($overlay_base . '.po', 'PO');
        file_put_contents($overlay_base . '.mo', 'MO');
        file_put_contents($overlay_base . '.lock', '');
        clearstatcache(true, $overlay_base . '.lock');
        $lock_inode_before = lstat($overlay_base . '.lock')['ino'];

        // Run in a child process with a hard timeout: without the re-entrancy guard this scenario is
        // the very same same-process self-deadlock testANestedWithOverlayLockForTheSameModuleReturns...
        // above guards against - the nested removeOverlay() would block forever on the very lock its
        // own enclosing withOverlayLock() call already holds.
        $result = $this->runPhpWithTimeout($this->nestedRemoveOverlayChildCode(), 5.0);

        $this->assertTrue($result['finished'], 'a nested removeOverlay() must return instead of hanging: ' . $result['output']);
        $this->assertSame(0, $result['exit_code'], $result['output']);

        clearstatcache(true, $overlay_base . '.po');
        clearstatcache(true, $overlay_base . '.mo');
        clearstatcache(true, $overlay_base . '.lock');
        $this->assertFileDoesNotExist($overlay_base . '.po', 'the nested removeOverlay() must still actually remove the overlay files');
        $this->assertFileDoesNotExist($overlay_base . '.mo');
        $this->assertFileExists(
            $overlay_base . '.lock',
            'a removeOverlay() nested inside an already-held lock must NOT delete the lock file - the outer caller still relies on it'
        );
        $this->assertSame(
            $lock_inode_before,
            lstat($overlay_base . '.lock')['ino'],
            'the lock file must stay the exact same one throughout - no retry/replace cycle for the nested call'
        );
    }

    private function nestedRemoveOverlayChildCode(): string
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

            MigratedLanguageFileSync::withOverlayLock(
                $manager,
                'de',
                'stest',
                $client_data_dir,
                function () use ($manager, $client_data_dir): void {
                    MigratedLanguageFileSync::removeOverlay($manager, 'de', 'stest', $client_data_dir);
                }
            );
            echo "done\n";
            PHP;

        return str_replace(
            ['__ROOT__', '__CLIENT_DATA_DIR__'],
            [var_export(ILIAS_ABSOLUTE_PATH, true), var_export($this->client_data_dir, true)],
            $template
        );
    }

    /**
     * Mutation: sync() (a reinstall after an uninstall) failing to create a fresh `.lock` - e.g. because
     * withOverlayLock()'s early-return guard (`!is_dir(...) && !isShipped(...)`) was weakened to also
     * skip locking once a `.lock` has ever existed before.
     */
    public function testASyncAfterRemoveOverlayCreatesANewLockFile(): void
    {
        $relative_path = 'components/ILIAS/Language/tests/ComponentTranslation/tmp-lock-shipped-' . bin2hex(random_bytes(4)) . '/';
        MigratedPoFixture::writeShippedPo(
            $relative_path,
            self::MODULE,
            'de',
            MigratedPoFixture::catalog(self::MODULE, ['greeting' => 'Hallo'])
        );
        $directory = MigratedPoFixture::directory(self::MODULE, $relative_path);
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $directory);
        $overlay_base = MigratedLanguageFilePaths::overlayBasePath($this->client_data_dir, $directory, 'de');

        try {
            MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', self::MODULE, ['greeting' => 'Hallo'], $this->client_data_dir);
            $this->assertFileExists($overlay_base . '.lock', 'sync() must leave a .lock file behind for later callers to serialize on');

            MigratedLanguageFileSync::removeOverlay($manager, 'de', self::MODULE, $this->client_data_dir);
            $this->assertFileDoesNotExist($overlay_base . '.lock', 'removeOverlay() (uninstall) must have removed it');

            MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', self::MODULE, ['greeting' => 'Servus'], $this->client_data_dir);
            $this->assertFileExists($overlay_base . '.lock', 'a reinstall/resync must create a fresh .lock file');
        } finally {
            MigratedPoFixture::removeShippedDirectory($relative_path);
        }
    }

    // --------------------------------------------- inode replaced while a waiter holds it

    /**
     * Polls (bounded by $timeout_seconds) until the process $pid has an open file descriptor whose
     * target is exactly $path - i.e. acquireLock() just called fopen($path) and is at or about to
     * reach its flock() call. Used as the sole synchronization primitive for the tests below: it lets
     * this process replace/symlink the lock file at exactly the right moment (right after the child
     * opened it, while this process still holds - or is about to take - the conflicting lock that makes
     * the child's flock() block for as long as needed), without any fixed sleep(). Linux-specific
     * (/proc): the PARENT (this test), not the child, relies on /proc/<pid>/fd here, so callers must
     * skip the test up front (see requireProcFdSupport()) rather than let this silently return false
     * on a platform without /proc.
     *
     * $ignored_fds excludes file descriptor NUMBERS the child inherited at fork() time from THIS
     * (parent/PHPUnit) process - e.g. the handle these tests already hold open on the very same lock
     * file before the child is even started, so it can block the child's first attempt immediately. Such
     * an inherited descriptor shows up in the child's /proc/<pid>/fd exactly like a genuinely
     * self-opened one and would otherwise be mistaken for the child having reached its own fopen() call
     * before it actually has - see seedIgnoredFds().
     *
     * @param list<string> $ignored_fds
     */
    private function waitForOpenFd(int $pid, string $path, float $timeout_seconds, array $ignored_fds = []): bool
    {
        $deadline = microtime(true) + $timeout_seconds;
        do {
            foreach ($this->openFdsFor($pid, $path) as $entry) {
                if (!in_array($entry, $ignored_fds, true)) {
                    return true;
                }
            }
            usleep(1_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Skips the calling test unless /proc/self/fd is available, i.e. unless waitForOpenFd() can work
     * at all on this platform - must be called before any lock file is taken or any child process is
     * started, so a skip never leaves anything to clean up.
     */
    private function requireProcFdSupport(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('This test requires /proc (Linux) to observe the child process\'s open file descriptors.');
        }
    }

    /**
     * @return list<string> the file descriptor numbers of $pid whose target is exactly the realpath()
     *   of $path - resolved once so a TMPDIR that is itself a symlink (readlink() on /proc/<pid>/fd/*
     *   always returns the fully resolved target) does not make a genuine match go unrecognized.
     */
    private function openFdsFor(int $pid, string $path): array
    {
        $matches = [];
        $entries = @scandir('/proc/' . $pid . '/fd');
        if ($entries === false) {
            return $matches;
        }
        $resolved_path = realpath($path) ?: $path;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (@readlink('/proc/' . $pid . '/fd/' . $entry) === $resolved_path) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    /**
     * The baseline to pass as waitForOpenFd()'s $ignored_fds: whichever of $pid's file descriptors
     * already point to $path at this very moment - meant to be called right after starting the child,
     * while this process still holds $path open itself (see the class comment of waitForOpenFd()).
     *
     * @return list<string>
     */
    private function seedIgnoredFds(int $pid, string $path): array
    {
        return $this->openFdsFor($pid, $path);
    }

    private function lockAttempts(): int
    {
        return (new \ReflectionClassConstant(MigratedLanguageFileSync::class, 'LOCK_ATTEMPTS'))->getValue();
    }

    /**
     * A single withOverlayLock() call for module/lang "stest"/"de", printing "RESULT:<value>" on
     * success or "EXCEPTION:<class>:<message>" if it throws, plus "CALLS:<n>" for how many times the
     * callback itself actually ran - run in a child process so this (parent) process is free to
     * manipulate the lock file on disk while the child's acquireLock() loop is running.
     *
     * $error_log_file, if given, is set as this child's `error_log` ini value BEFORE the
     * withOverlayLock() call, so acquireLock()'s logWarning() fallback (no $DIC in this bare child
     * process) writes any "removed or replaced" warning there instead of to the real PHP error log -
     * letting the test assert whether a warning was (or, just as importantly, was not) logged.
     */
    private function acquireOnceChildCode(?string $error_log_file = null): string
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

            $error_log_file = __ERROR_LOG_FILE__;
            if ($error_log_file !== null) {
                ini_set('error_log', $error_log_file);
            }

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

            try {
                $calls = 0;
                $result = MigratedLanguageFileSync::withOverlayLock(
                    $manager,
                    'de',
                    'stest',
                    $client_data_dir,
                    static function () use (&$calls): string {
                        $calls++;

                        return 'ok';
                    }
                );
                echo 'RESULT:' . $result . "\n";
                echo 'CALLS:' . $calls . "\n";
            } catch (\Throwable $e) {
                echo 'EXCEPTION:' . get_class($e) . ':' . $e->getMessage() . "\n";
            }
            PHP;

        return str_replace(
            ['__ROOT__', '__CLIENT_DATA_DIR__', '__ERROR_LOG_FILE__'],
            [
                var_export(ILIAS_ABSOLUTE_PATH, true),
                var_export($this->client_data_dir, true),
                var_export($error_log_file, true),
            ],
            $template
        );
    }

    /**
     * @return array{pid: int, process: resource, pipes: array<int, resource>}
     */
    private function startAcquireOnceChild(?string $error_log_file = null): array
    {
        $process = proc_open([PHP_BINARY, '-r', $this->acquireOnceChildCode($error_log_file)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $this->fail('Could not start the child PHP process.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $status = proc_get_status($process);

        return ['pid' => $status['pid'], 'process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{finished: bool, output: string}
     */
    private function drainChild($process, array $pipes, float $timeout_seconds): array
    {
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

                return ['finished' => true, 'output' => $output];
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

        return ['finished' => false, 'output' => $output];
    }

    /**
     * Terminates $child's process if a failed assertion earlier in the test left it running (e.g.
     * still blocked in acquireLock()'s retry loop) and closes its pipes/process handle - so no test
     * failure below ever leaks an orphaned child process. Safe to call again after drainChild()
     * already closed everything (is_resource() is false for an already-closed process/pipe).
     *
     * @param array{pid: int, process: resource, pipes: array<int, resource>} $child
     */
    private function terminateChildIfRunning(array $child): void
    {
        if (is_resource($child['process'])) {
            $status = proc_get_status($child['process']);
            if ($status['running']) {
                proc_terminate($child['process'], 9);
                usleep(100_000);
            }
        }
        foreach ($child['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($child['process'])) {
            @proc_close($child['process']);
        }
    }

    /**
     * Replaces the file at $lock_file with a brand-new inode, exclusively locked via $next_handle,
     * only after first taking that lock and THEN releasing $current_handle - so a waiter blocked on
     * $current_handle's lock is only ever unblocked once the replacement is already fully in place
     * (no window where the path is momentarily unlocked or missing).
     *
     * @param resource $current_handle
     * @return resource the new handle, still holding its lock
     */
    private function replaceLockedFile(string $lock_file, $current_handle)
    {
        $this->assertTrue((bool) @unlink($lock_file), 'could not unlink the lock file to replace it');
        $next_handle = fopen($lock_file, 'c');
        $this->assertNotFalse($next_handle, 'could not recreate the lock file');
        $this->assertTrue(flock($next_handle, LOCK_EX));
        flock($current_handle, LOCK_UN);
        fclose($current_handle);

        return $next_handle;
    }

    /**
     * Mutation: acquireLock() returning/keeping a handle to an inode that was deleted (and replaced)
     * while this process waited on it - see isHandleOfPath(). The lock file is replaced exactly twice
     * while a real, separate process is blocked trying to lock it (synchronized via flock() blocking
     * plus /proc-based confirmation, not by timing), so it must retry twice and end up holding the
     * THIRD, now-stable file - never the first or second one it originally opened.
     */
    public function testAWaiterThatLocksAnInodeDeletedInTheMeantimeRetriesAndEndsUpHoldingTheNewFile(): void
    {
        $this->requireProcFdSupport();
        $this->seedLockDirectory();
        $lock_file = $this->lockFile();

        $current_handle = fopen($lock_file, 'c');
        $this->assertNotFalse($current_handle);
        $this->assertTrue(flock($current_handle, LOCK_EX));

        $child = $this->startAcquireOnceChild();
        $ignored_fds = $this->seedIgnoredFds($child['pid'], $lock_file);
        try {
            for ($round = 1; $round <= 2; $round++) {
                $this->assertTrue(
                    $this->waitForOpenFd($child['pid'], $lock_file, 5.0, $ignored_fds),
                    'the child never opened the lock file for attempt ' . $round
                );
                $current_handle = $this->replaceLockedFile($lock_file, $current_handle);
            }

            $this->assertTrue(
                $this->waitForOpenFd($child['pid'], $lock_file, 5.0, $ignored_fds),
                'the child never opened the lock file for the final, stable attempt'
            );
            clearstatcache(true, $lock_file);
            $final_inode = lstat($lock_file)['ino'];
            flock($current_handle, LOCK_UN);
            fclose($current_handle);
            $current_handle = null;

            $result = $this->drainChild($child['process'], $child['pipes'], 5.0);
            $this->assertTrue($result['finished'], 'the child must not hang: ' . $result['output']);
            $this->assertStringContainsString('RESULT:ok', $result['output']);

            clearstatcache(true, $lock_file);
            $this->assertSame(
                $final_inode,
                lstat($lock_file)['ino'],
                'the waiter must end up holding the file that was actually left in place, not trigger a further replacement'
            );
        } finally {
            if (is_resource($current_handle)) {
                flock($current_handle, LOCK_UN);
                fclose($current_handle);
            }
            $this->terminateChildIfRunning($child);
        }
    }

    /**
     * Mutation: LOCK_ATTEMPTS's retry loop not giving up - the lock file is replaced on every single
     * one of its attempts, so acquireLock() must exhaust its budget and give up: per acquireLock()'s
     * contract, that means returning `null` and letting lockAndRun() fall back to running $callback
     * UNLOCKED exactly once (logged as a warning, via the no-$DIC error_log() fallback captured here
     * through this bare child process's own `error_log` ini setting) instead of retrying forever,
     * bubbling up an exception, or running the callback more than once.
     */
    public function testTheLockFileReplacedOnEveryAttemptExhaustsTheRetriesAndRunsCallbackOnceUnlockedWithAWarningLogged(): void
    {
        $this->requireProcFdSupport();
        $this->seedLockDirectory();
        $lock_file = $this->lockFile();
        $attempts = $this->lockAttempts();
        $error_log_file = $this->client_data_dir . '-error-log-exhausted.txt';

        $current_handle = fopen($lock_file, 'c');
        $this->assertNotFalse($current_handle);
        $this->assertTrue(flock($current_handle, LOCK_EX));

        $child = $this->startAcquireOnceChild($error_log_file);
        $ignored_fds = $this->seedIgnoredFds($child['pid'], $lock_file);
        try {
            for ($round = 1; $round <= $attempts; $round++) {
                $this->assertTrue(
                    $this->waitForOpenFd($child['pid'], $lock_file, 5.0, $ignored_fds),
                    'the child never opened the lock file for attempt ' . $round
                );
                $current_handle = $this->replaceLockedFile($lock_file, $current_handle);
            }
            flock($current_handle, LOCK_UN);
            fclose($current_handle);
            $current_handle = null;

            $result = $this->drainChild($child['process'], $child['pipes'], 5.0);

            $this->assertTrue($result['finished'], 'the child must not hang: ' . $result['output']);
            $this->assertStringContainsString(
                'RESULT:ok',
                $result['output'],
                'exhausting LOCK_ATTEMPTS must fall back to running the callback unlocked, not throw'
            );
            $this->assertStringContainsString('CALLS:1', $result['output'], 'the callback must run exactly once');
            $this->assertStringNotContainsString('EXCEPTION', $result['output']);

            $this->assertFileExists($error_log_file, 'exhausting LOCK_ATTEMPTS must log a warning');
            $warning = (string) file_get_contents($error_log_file);
            $this->assertStringContainsString('removed or replaced', $warning);
            $this->assertStringContainsString((string) $attempts, $warning);
        } finally {
            if (is_resource($current_handle)) {
                flock($current_handle, LOCK_UN);
                fclose($current_handle);
            }
            $this->terminateChildIfRunning($child);
            @unlink($error_log_file);
        }
    }

    /**
     * Counterpart to the exhausted-retries case above: the lock file is replaced only on the FIRST
     * attempt, so the second one finds a stable file - acquireLock() must succeed normally (a real
     * lock, not the unlocked fallback) and must not log the "removed or replaced" warning at all.
     */
    public function testTheLockFileReplacedOnlyOnTheFirstAttemptStillAcquiresTheLockNormallyWithoutAWarning(): void
    {
        $this->requireProcFdSupport();
        $this->seedLockDirectory();
        $lock_file = $this->lockFile();
        $error_log_file = $this->client_data_dir . '-error-log-recovered.txt';

        $current_handle = fopen($lock_file, 'c');
        $this->assertNotFalse($current_handle);
        $this->assertTrue(flock($current_handle, LOCK_EX));

        $child = $this->startAcquireOnceChild($error_log_file);
        $ignored_fds = $this->seedIgnoredFds($child['pid'], $lock_file);
        try {
            $this->assertTrue(
                $this->waitForOpenFd($child['pid'], $lock_file, 5.0, $ignored_fds),
                'the child never opened the lock file for the first attempt'
            );
            $current_handle = $this->replaceLockedFile($lock_file, $current_handle);

            $this->assertTrue(
                $this->waitForOpenFd($child['pid'], $lock_file, 5.0, $ignored_fds),
                'the child never opened the lock file for the second, now-stable attempt'
            );
            flock($current_handle, LOCK_UN);
            fclose($current_handle);
            $current_handle = null;

            $result = $this->drainChild($child['process'], $child['pipes'], 5.0);

            $this->assertTrue($result['finished'], 'the child must not hang: ' . $result['output']);
            $this->assertStringContainsString('RESULT:ok', $result['output']);
            $this->assertStringContainsString('CALLS:1', $result['output'], 'the callback must run exactly once');
            $this->assertStringNotContainsString('EXCEPTION', $result['output']);

            $this->assertSame(
                '',
                (string) @file_get_contents($error_log_file),
                'recovering on a later attempt must not log the "removed or replaced" warning'
            );
        } finally {
            if (is_resource($current_handle)) {
                flock($current_handle, LOCK_UN);
                fclose($current_handle);
            }
            $this->terminateChildIfRunning($child);
            @unlink($error_log_file);
        }
    }

    /**
     * Mutation: the symbolic link check (assertNoSymbolicLinkBelowOverlayRoot()) only running once,
     * before the retry loop, instead of on every attempt - the lock file is a regular file for the
     * FIRST attempt (so a check that only ran up front would pass) and is only replaced by a symlink
     * while the child is blocked waiting to (re-)lock it, i.e. discovered exclusively on a LATER
     * attempt.
     */
    public function testTheSymbolicLinkCheckAppliesOnEveryAttemptNotJustTheFirst(): void
    {
        $this->requireProcFdSupport();
        $this->seedLockDirectory();
        $lock_file = $this->lockFile();
        $decoy = $this->client_data_dir . '-decoy-lock-target';
        file_put_contents($decoy, 'DECOY');

        $current_handle = fopen($lock_file, 'c');
        $this->assertNotFalse($current_handle);
        $this->assertTrue(flock($current_handle, LOCK_EX));

        $child = $this->startAcquireOnceChild();
        $ignored_fds = $this->seedIgnoredFds($child['pid'], $lock_file);
        try {
            $this->assertTrue(
                $this->waitForOpenFd($child['pid'], $lock_file, 5.0, $ignored_fds),
                'the child never opened the lock file for the first attempt'
            );
            $this->assertTrue((bool) @unlink($lock_file));
            symlink($decoy, $lock_file);
            flock($current_handle, LOCK_UN);
            fclose($current_handle);
            $current_handle = null;

            $result = $this->drainChild($child['process'], $child['pipes'], 5.0);

            $this->assertTrue($result['finished'], 'the child must not hang: ' . $result['output']);
            $this->assertStringContainsString(
                'RESULT:ok',
                $result['output'],
                'a symlink discovered on a LATER attempt must fall back to running unlocked, not bubble up an exception'
            );
            $this->assertSame('DECOY', file_get_contents($decoy), 'the symlink target must never be touched');
            $this->assertSame($decoy, readlink($lock_file), 'the symlink itself must be left in place');
        } finally {
            @unlink($decoy);
            if (is_resource($current_handle)) {
                flock($current_handle, LOCK_UN);
                fclose($current_handle);
            }
            $this->terminateChildIfRunning($child);
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
