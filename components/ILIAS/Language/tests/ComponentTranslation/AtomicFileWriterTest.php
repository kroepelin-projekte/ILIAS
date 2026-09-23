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

namespace ILIAS\Language\Tests\ComponentTranslation;

use ILIAS\Language\ComponentTranslation\AtomicFileWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AtomicFileWriter: the overlay `.po`/`.mo` are replaced via a temporary file in the same directory
 * plus rename(), so ilLanguage never reads a half-written `.mo`.
 */
class AtomicFileWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ilias_atomic_' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775);
    }

    protected function tearDown(): void
    {
        \MigratedPoFixture::removeDirectory($this->directory);
    }

    /**
     * @return list<string>
     */
    private function directoryListing(): array
    {
        return array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
    }

    public function testWritesANewFileAndLeavesNoTemporaryFileBehind(): void
    {
        AtomicFileWriter::write($this->directory . '/target.po', "inhalt\n");

        $this->assertSame("inhalt\n", file_get_contents($this->directory . '/target.po'));
        $this->assertSame(['target.po'], $this->directoryListing());
    }

    public function testReplacesAnExistingFile(): void
    {
        file_put_contents($this->directory . '/target.po', 'alt');

        AtomicFileWriter::write($this->directory . '/target.po', 'neu');

        $this->assertSame('neu', file_get_contents($this->directory . '/target.po'));
        $this->assertSame(['target.po'], $this->directoryListing());
    }

    public function testWritesEmptyContent(): void
    {
        AtomicFileWriter::write($this->directory . '/empty.mo', '');

        $this->assertSame('', file_get_contents($this->directory . '/empty.mo'));
    }

    /**
     * tempnam() creates 0600 files; the result must have the permissions a plain
     * file_put_contents() would give (0666 & ~umask) so e.g. the web server can read what the CLI
     * Setup wrote.
     */
    public function testTheWrittenFileDoesNotKeepTheRestrictiveTempnamPermissions(): void
    {
        $previous = umask(0022);
        try {
            AtomicFileWriter::write($this->directory . '/target.mo', 'x');
        } finally {
            umask($previous);
        }

        clearstatcache();
        $this->assertSame('0644', sprintf('%04o', fileperms($this->directory . '/target.mo') & 0777));
    }

    /**
     * An existing target keeps its own mode - not the "new file" mode from testTheWrittenFileDoesNotKeepTheRestrictiveTempnamPermissions()
     * above, and not whatever tempnam() or umask would otherwise produce.
     *
     * Mutation: the `is_file($file) ? @fileperms($file) : ...` branch being dropped or inverted, or
     * the `& 07777` mask being wrong (e.g. dropping the setuid/setgid/sticky bits it must preserve).
     */
    public function testAnExistingTargetKeepsItsOwnModeInsteadOfANewFilesMode(): void
    {
        file_put_contents($this->directory . '/target.po', 'alt');
        chmod($this->directory . '/target.po', 0600);
        $previous_umask = umask(0022); // would produce 0644 for a NEW file - must not leak in here
        try {
            AtomicFileWriter::write($this->directory . '/target.po', 'neu');
        } finally {
            umask($previous_umask);
        }

        clearstatcache();
        $this->assertSame('0600', sprintf('%04o', fileperms($this->directory . '/target.po') & 0777));
    }

    /**
     * grantsAtLeast() is the pure decision acceptUnchangeableMode() delegates to: whether every
     * permission bit $intended_mode grants is also granted by $effective_mode.
     *
     * @dataProvider grantsAtLeastProvider
     */
    public function testGrantsAtLeast(int $effective_mode, int $intended_mode, bool $expected): void
    {
        $this->assertSame($expected, AtomicFileWriter::grantsAtLeast($effective_mode, $intended_mode));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: bool}>
     */
    public static function grantsAtLeastProvider(): array
    {
        return [
            '0600 lacks group/other read the intended 0644 needs' => [0600, 0644, false],
            '0777 grants everything, including 0644' => [0777, 0644, true],
            '0644 grants exactly the identical 0644 it is compared against' => [0644, 0644, true],
            '0640 lacks group-write and other-read the intended 0664 needs' => [0640, 0664, false],
            '0664 grants everything the less permissive 0644 needs' => [0664, 0644, true],
        ];
    }

    // NOT COVERED: the post-rename confinement re-check in write() (the `if ($resolved_root !== null)`
    // block after the `rename()`, AtomicFileWriter.php ~99-109) actually firing. It only fires when
    // `realpath(dirname($file))` resolves DIFFERENTLY right after the rename() than it did in the
    // pre-write check moments earlier - i.e. some symlink below the confined directory was swapped
    // WHILE this single, synchronous write() call was executing (the TOCTOU window the class comment
    // documents as an accepted residual risk). $resolved_root itself is fixed for the whole call (it is
    // resolved once, into a local variable, before the pre-write check), so reassigning what
    // $confine_to_directory points to cannot relax anything either - only a mutation of dirname($file)'s
    // OWN resolution, mid-call, reproduces it. AtomicFileWriter is `final`, has only static methods and
    // real filesystem calls (no injectable seam), and unlike MigratedLanguageFileSyncLockTest's lock
    // scenarios there is no blocking primitive (no flock()) anywhere in write() a concurrent process
    // could synchronize against - the whole tempnam()/chmod()/rename() sequence runs in well under a
    // millisecond, so a second process racing to swap a symlink would have to land inside that window
    // blindly. That could not be reproduced deterministically in this sandbox.

    // NOT COVERED: acceptUnchangeableMode()'s "chmod() failed, but the effective mode already grants
    // at least as much as intended -> no exception" vs. "... grants less -> RuntimeException" branching
    // (AtomicFileWriter.php ~98-116). AtomicFileWriter is `final` with only static methods and real
    // filesystem calls - no injectable seam (no filesystem abstraction, no constructor to subclass) -
    // and simulating a chmod() failure without also breaking the write itself could not be reproduced
    // in this sandbox: `chattr +i` (the one lever that makes chmod() fail while leaving writes to the
    // existing content alone) is refused with "Operation not permitted" on both the reference host and
    // the ILIAS container - even as root - because neither filesystem (the host's, and the container's
    // overlay2) supports the immutable attribute here.

    public function testThrowsWhenTheTargetDirectoryDoesNotExist(): void
    {
        set_error_handler(static fn(): bool => true, E_NOTICE | E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Could not create a temporary file/');
            AtomicFileWriter::write($this->directory . '/missing/target.po', 'x');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Root-proof failure of the final rename(): the target is a directory. The temporary file must be
     * removed again.
     */
    public function testThrowsWhenTheTargetCannotBeReplacedAndRemovesTheTemporaryFile(): void
    {
        mkdir($this->directory . '/target.po');

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            AtomicFileWriter::write($this->directory . '/target.po', 'x');
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not replace', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['target.po'], $this->directoryListing());
        $this->assertDirectoryExists($this->directory . '/target.po');
    }

    /**
     * Regression: tempnam() returns the resolved directory; comparing it with the unresolved one
     * rejected every target below a symlinked directory, a "//" or a relative path.
     */
    public function testWritesIntoASymlinkedDirectory(): void
    {
        $real = $this->directory . '/real';
        mkdir($real);
        symlink($real, $this->directory . '/link');

        AtomicFileWriter::write($this->directory . '/link/target.po', 'x');

        $this->assertSame('x', file_get_contents($real . '/target.po'));
        $this->assertSame(['target.po'], array_values(array_diff(scandir($real), ['.', '..'])));
    }

    public function testWritesWithADoubleSlashInThePath(): void
    {
        mkdir($this->directory . '/sub');

        AtomicFileWriter::write($this->directory . '//sub//target.po', 'x');

        $this->assertSame('x', file_get_contents($this->directory . '/sub/target.po'));
        $this->assertSame(['target.po'], array_values(array_diff(scandir($this->directory . '/sub'), ['.', '..'])));
    }

    public function testWritesToARelativePath(): void
    {
        $cwd = getcwd();
        chdir($this->directory);
        try {
            AtomicFileWriter::write('relative.po', 'x');
            mkdir('nested');
            AtomicFileWriter::write('nested/../nested/target.po', 'y');
        } finally {
            chdir($cwd);
        }

        $this->assertSame('x', file_get_contents($this->directory . '/relative.po'));
        $this->assertSame('y', file_get_contents($this->directory . '/nested/target.po'));
        $this->assertSame(['nested', 'relative.po'], $this->directoryListing());
    }

    /**
     * Writing below a regular file (not a directory) must fail cleanly - also as root.
     */
    public function testThrowsWhenAPathComponentIsARegularFile(): void
    {
        file_put_contents($this->directory . '/file', 'x');

        set_error_handler(static fn(): bool => true, E_NOTICE | E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            AtomicFileWriter::write($this->directory . '/file/target.po', 'x');
        } finally {
            restore_error_handler();
        }
    }
}
