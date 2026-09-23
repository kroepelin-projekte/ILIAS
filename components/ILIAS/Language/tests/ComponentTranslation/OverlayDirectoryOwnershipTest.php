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
 * MigratedLanguageFileSync::findUnwritableOverlayDirectories()'s $for_user_id evaluation (private
 * isWritableDirectoryFor(), only reachable through this public method): with a user id given and the
 * POSIX extension available, writability is decided from the directory's owner/group/mode bits for
 * THAT user - never from is_writable(), which would be meaningless for a Setup run as root checking
 * on behalf of the web server user.
 */
class OverlayDirectoryOwnershipTest extends TestCase
{
    private const string MODULE = 'stest';

    private string $fixture_directory;
    private string $client_data_dir;
    private LanguageFileDirectory $directory;
    private LanguageFileDirectoryManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../../'));
        }
        if (!function_exists('posix_getpwuid') || !function_exists('posix_getgrgid')) {
            $this->markTestSkipped('Requires the POSIX extension (posix_getpwuid()/posix_getgrgid()).');
        }

        $this->fixture_directory = __DIR__ . '/tmp-ownership-fixtures-' . bin2hex(random_bytes(4));
        mkdir($this->fixture_directory, 0775, true);
        $this->client_data_dir = sys_get_temp_dir() . '/ilias_mlfs_ownership_test_' . bin2hex(random_bytes(4));
        mkdir($this->client_data_dir, 0775, true);

        $this->directory = MigratedPoFixture::directory(
            self::MODULE,
            'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '/'
        );
        $this->manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $this->directory);
        MigratedPoFixture::writePo(
            $this->fixture_directory . '/' . self::MODULE . '_de.po',
            MigratedPoFixture::catalog(self::MODULE, ['greeting' => 'Hallo'])
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->overlayDirectory())) {
            chmod($this->overlayDirectory(), 0775);
        }
        MigratedPoFixture::removeDirectory($this->fixture_directory);
        MigratedPoFixture::removeDirectory($this->client_data_dir);

        parent::tearDown();
    }

    private function overlayDirectory(): string
    {
        return dirname(MigratedLanguageFilePaths::overlayBasePath($this->client_data_dir, $this->directory, 'de'));
    }

    /**
     * @return list<string>
     */
    private function findUnwritable(?int $for_user_id): array
    {
        return MigratedLanguageFileSync::findUnwritableOverlayDirectories(
            $this->manager,
            ILIAS_ABSOLUTE_PATH,
            ['de'],
            $this->client_data_dir,
            $for_user_id
        );
    }

    /**
     * Only the leaf overlay directory itself gets $mode - every parent is created at a normal 0775
     * (traversable for everyone in the test), exactly what isCreatableOrWritableDirectory() actually
     * evaluates ("the closest existing path" is the overlay directory itself here). Giving an
     * intermediate directory a restrictive mode instead would break the test process's own traversal
     * into it (unrelated to the behaviour under test) rather than exercise it.
     */
    private function seedOverlayDirectory(int $mode): void
    {
        $overlay_directory = $this->overlayDirectory();
        if (!is_dir(dirname($overlay_directory))) {
            mkdir(dirname($overlay_directory), 0775, true);
        }
        if (!is_dir($overlay_directory)) {
            mkdir($overlay_directory, 0775);
        }
        chmod($overlay_directory, $mode); // avoid mkdir()'s own mode being masked by umask
        clearstatcache(true, $overlay_directory);
    }

    private function assertReported(int $mode, int $for_user_id, string $message): void
    {
        $this->seedOverlayDirectory($mode);

        $this->assertSame([$this->overlayDirectory()], $this->findUnwritable($for_user_id), $message);
    }

    private function assertNotReported(int $mode, int $for_user_id, string $message): void
    {
        $this->seedOverlayDirectory($mode);

        $this->assertSame([], $this->findUnwritable($for_user_id), $message);
    }

    // ---------------------------------------------------------------- for_user_id = 0 (root)

    /**
     * Mutation: the `$for_user_id === 0` short-circuit being removed - root must be considered able
     * to write regardless of mode (0000: nobody else could).
     */
    public function testRootMayAlwaysWriteRegardlessOfMode(): void
    {
        $this->assertNotReported(0000, 0, 'root (uid 0) must always be able to create/replace files');
    }

    // ------------------------------------------------------- for_user_id = the directory's owner

    /**
     * Mutation: the owner branch using the wrong mask (e.g. read instead of write+search, or a
     * group/other mask) - the directory's own owner needs the OWNER write+execute bits, group and
     * other bits are irrelevant to them.
     */
    public function testTheOwnerNeedsTheOwnerWriteAndSearchBitsRegardlessOfGroupOrOtherBits(): void
    {
        // We create the overlay directory ourselves (see assertReported()/assertNotReported()), so
        // its owner is always the current process - exactly what fileowner() would report once it
        // exists.
        $owner = posix_getuid();

        $this->assertReported(
            0577, // owner: r-x (no write), group+other: rwx - irrelevant, the owner still can't write
            $owner,
            'the owner lacking the write bit must be reported even though group/other have it'
        );
        $this->assertNotReported(
            0700, // owner: rwx, group+other: nothing
            $owner,
            'the owner having the write+search bits must be enough on its own'
        );
    }

    /**
     * Same as above, but with a directory chown()ed to an arbitrary, non-current uid - proving
     * isWritableDirectoryFor() really evaluates $stat['uid'] against $for_user_id and not, say, the
     * current process's uid. Only possible as root.
     */
    public function testAnArbitraryChownedOwnerNeedsTheOwnerWriteAndSearchBits(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Requires root to chown() the overlay directory to an arbitrary owner.');
        }
        $foreign_owner = 33; // www-data, present on both the reference host and the ILIAS container
        $this->seedOverlayDirectory(0555);
        chown($this->overlayDirectory(), $foreign_owner);
        clearstatcache(true, $this->overlayDirectory());

        $this->assertSame(
            [$this->overlayDirectory()],
            $this->findUnwritable($foreign_owner),
            'the chown()ed owner lacking the write bit must be reported'
        );

        chmod($this->overlayDirectory(), 0775);
        clearstatcache(true, $this->overlayDirectory());

        $this->assertSame(
            [],
            $this->findUnwritable($foreign_owner),
            'the chown()ed owner having the write+search bits must not be reported'
        );
    }

    // ------------------------------------------------------------- for_user_id = a foreign uid

    /**
     * Mutation: the "other" branch being skipped, or using the owner/group mask instead - a uid that
     * is neither the owner nor in its group is governed by the OTHER bits alone, however generous
     * the owner/group bits are.
     */
    public function testAForeignUidNeedsTheOtherWriteAndSearchBitsEvenWhenOwnerAndGroupHaveThem(): void
    {
        $foreign_uid = 1; // "daemon", present on both the reference host and the ILIAS container

        $this->assertReported(
            0775, // owner rwx, group rwx, other r-x (no write) - a stranger still can't write
            $foreign_uid,
            'other lacking the write bit must be reported even though owner/group have it'
        );
        $this->assertNotReported(
            0777, // other now has rwx as well
            $foreign_uid,
            'other having the write+search bits must be enough for a stranger'
        );
    }
}
