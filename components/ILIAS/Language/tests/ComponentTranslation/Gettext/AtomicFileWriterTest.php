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

namespace ILIAS\Language\Tests\ComponentTranslation\Gettext;

use ILIAS\Language\ComponentTranslation\Gettext\AtomicFileWriter;
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
