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
use ILIAS\Language\ComponentTranslation\Gettext\AtomicFileWriter;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use PHPUnit\Framework\TestCase;

/**
 * MigratedLanguageFileSync never follows a symbolic link below the overlay root (CWE-59, see
 * assertNoSymbolicLinkBelowOverlayRoot()): a link placed anywhere in the client data directory - as
 * an intermediate directory, or as the `.po`/`.mo`/`.lock` file itself - must never redirect a write,
 * a lock or a removal outside of it. Only the overlay root itself (`<client data dir>/lang`, and
 * everything above it) is exempt, since that is the administrator's own configuration.
 *
 * Each scenario also proves the decoy the link points to is left completely untouched - a
 * RuntimeException alone would not catch a mutation that checks-then-still-follows the link.
 */
class MigratedLanguageFileSyncSymlinkTest extends TestCase
{
    private const string MODULE = 'stest';

    private string $fixture_directory;
    private string $client_data_dir;
    private string $decoy_directory;
    private LanguageFileDirectory $directory;
    private LanguageFileDirectoryManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../../'));
        }

        $this->fixture_directory = __DIR__ . '/tmp-symlink-fixtures-' . bin2hex(random_bytes(4));
        mkdir($this->fixture_directory, 0775, true);
        $this->client_data_dir = sys_get_temp_dir() . '/ilias_mlfs_symlink_test_' . bin2hex(random_bytes(4));
        mkdir($this->client_data_dir, 0775, true);
        $this->decoy_directory = sys_get_temp_dir() . '/ilias_mlfs_symlink_decoy_' . bin2hex(random_bytes(4));
        mkdir($this->decoy_directory, 0775, true);

        // An extra "sub" path segment below the fixture directory, so an intermediate directory can
        // be replaced by a symlink without having to touch the fixture/client data directories
        // themselves.
        $this->directory = MigratedPoFixture::directory(
            self::MODULE,
            'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '/sub/'
        );
        $this->manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $this->directory);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->fixture_directory);
        MigratedPoFixture::removeDirectory($this->client_data_dir);
        MigratedPoFixture::removeDirectory($this->decoy_directory);

        parent::tearDown();
    }

    private function seedShipped(string $value = 'Hallo'): void
    {
        MigratedPoFixture::writePo(
            $this->shippedPo(),
            MigratedPoFixture::catalog(self::MODULE, ['greeting' => $value])
        );
    }

    private function shippedPo(): string
    {
        return $this->fixture_directory . '/sub/' . self::MODULE . '_de.po';
    }

    private function overlayBase(): string
    {
        return MigratedLanguageFilePaths::overlayBasePath($this->client_data_dir, $this->directory, 'de');
    }

    private function lockFile(): string
    {
        return $this->overlayBase() . '.lock';
    }

    private function sync(array $entries = ['greeting' => 'Hallo']): void
    {
        MigratedLanguageFileSync::sync(
            $this->manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            self::MODULE,
            $entries,
            $this->client_data_dir
        );
    }

    /**
     * @return list<string>
     */
    private function decoyListing(): array
    {
        return array_values(array_diff(scandir($this->decoy_directory) ?: [], ['.', '..']));
    }

    // -------------------------------------------------- intermediate directory

    /**
     * Mutation: assertNoSymbolicLinkBelowOverlayRoot() not being called (or not checking every
     * intermediate component) for the `.po` path before it is written.
     */
    public function testASymlinkedIntermediateDirectoryIsRejectedAndItsTargetIsLeftUntouched(): void
    {
        $this->seedShipped();
        mkdir(dirname(dirname($this->overlayBase())), 0775, true);
        symlink($this->decoy_directory, dirname($this->overlayBase()));

        try {
            $this->sync();
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
        }

        $this->assertSame([], $this->decoyListing(), 'nothing may be written into the linked-to directory');
        $this->assertSame($this->decoy_directory, readlink(dirname($this->overlayBase())), 'the symlink itself must be left in place');
    }

    // ------------------------------------------------------------------- .po

    /**
     * Mutation: the `assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $overlay_po)` call being
     * removed - a `.po` path that is itself a symlink must not be replaced via that link.
     */
    public function testASymlinkedOverlayPoIsRejectedAndItsTargetIsLeftUntouched(): void
    {
        $this->seedShipped();
        mkdir(dirname($this->overlayBase()), 0775, true);
        $decoy = $this->decoy_directory . '/decoy.po';
        file_put_contents($decoy, 'DECOY');
        symlink($decoy, $this->overlayBase() . '.po');

        try {
            $this->sync();
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
        }

        $this->assertSame('DECOY', file_get_contents($decoy), 'the file the link points to must not be modified');
        $this->assertSame($decoy, readlink($this->overlayBase() . '.po'), 'the symlink itself must not be replaced');
    }

    // ------------------------------------------------------------------- .mo

    /**
     * Mutation: the `assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $overlay_mo)` call being
     * removed - exercised with a missing `.po` (a fresh overlay) so this is specifically the `.mo`
     * check, not the `.po` one, that rejects the write.
     */
    public function testASymlinkedOverlayMoIsRejectedAndItsTargetIsLeftUntouched(): void
    {
        $this->seedShipped();
        mkdir(dirname($this->overlayBase()), 0775, true);
        $decoy = $this->decoy_directory . '/decoy.mo';
        file_put_contents($decoy, 'DECOY');
        symlink($decoy, $this->overlayBase() . '.mo');

        try {
            $this->sync();
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
        }

        $this->assertSame('DECOY', file_get_contents($decoy));
        $this->assertSame($decoy, readlink($this->overlayBase() . '.mo'));
        $this->assertFileDoesNotExist($this->overlayBase() . '.po', 'the .po must not have been written either');
    }

    // ----------------------------------------------------------------- .lock

    /**
     * A symlinked lock file cannot be a RuntimeException from sync()'s point of view: acquireLock()
     * catches it internally and falls back to running $callback WITHOUT a lock (see
     * withOverlayLock()'s own documentation) - exactly like a lock file that cannot be opened at all.
     * What must still hold: the symlink is never followed (its target is untouched) and the actual
     * overlay write still succeeds normally.
     *
     * Mutation: acquireLock() calling fopen()/flock() on the lock path without checking it for a
     * symlink first.
     */
    public function testASymlinkedLockFileIsNeverFollowedAndTheOverlayIsStillWrittenUnlocked(): void
    {
        $this->seedShipped();
        mkdir(dirname($this->lockFile()), 0775, true);
        $decoy = $this->decoy_directory . '/decoy.lock';
        file_put_contents($decoy, 'DECOY');
        symlink($decoy, $this->lockFile());

        $this->sync(['greeting' => 'Servus']);

        $this->assertSame('DECOY', file_get_contents($decoy), 'the lock file link target must never be opened/locked');
        $this->assertSame($decoy, readlink($this->lockFile()), 'the symlink itself must not be replaced');
        $this->assertSame(
            ['greeting' => 'Servus'],
            MigratedPoFixture::readMo($this->overlayBase() . '.mo'),
            'the overlay write itself must still succeed even though no lock could be taken'
        );
    }

    // ------------------------------------------------------------ removeOverlay

    /**
     * Mutation: removeOverlayFiles() unlinking `$base . '.po'` without first asserting it is not a
     * symlink.
     */
    public function testRemoveOverlayRejectsASymlinkedPoAndLeavesItsTargetAndTheMoUntouched(): void
    {
        $overlay_dir = dirname($this->overlayBase());
        mkdir($overlay_dir, 0775, true);
        file_put_contents($this->overlayBase() . '.mo', 'REAL MO');
        $decoy = $this->decoy_directory . '/decoy.po';
        file_put_contents($decoy, 'DECOY');
        symlink($decoy, $this->overlayBase() . '.po');

        try {
            MigratedLanguageFileSync::removeOverlay($this->manager, 'de', self::MODULE, $this->client_data_dir);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
        }

        $this->assertSame('DECOY', file_get_contents($decoy));
        $this->assertSame($decoy, readlink($this->overlayBase() . '.po'), 'the symlink must not have been unlinked');
        $this->assertSame('REAL MO', file_get_contents($this->overlayBase() . '.mo'), 'the .mo must not have been removed either');
    }

    // -------------------------------------------------- allowed: the root itself

    /**
     * The overlay root itself ("<client data dir>/lang") may be a symlink (e.g. a mounted data
     * volume) - only what is BELOW it is protected. Regression guard alongside the existing
     * "client data dir is a symlink" coverage in MigratedLanguageFileSyncTest, this time for the
     * "lang" segment itself being the link.
     */
    public function testASymlinkOnTheOverlayRootItselfIsAllowed(): void
    {
        $this->seedShipped();
        $real_lang_dir = $this->client_data_dir . '-real-lang';
        mkdir($real_lang_dir, 0775, true);
        symlink($real_lang_dir, $this->client_data_dir . '/lang');

        $this->sync(['greeting' => 'Servus']);

        $this->assertSame(
            ['greeting' => 'Servus'],
            MigratedPoFixture::readMo($this->overlayBase() . '.mo'),
            'the write must go through the symlinked root into the real directory'
        );

        MigratedPoFixture::removeDirectory($real_lang_dir);
    }

    // ------------------------------------------------- AtomicFileWriter confinement

    /**
     * Mutation: AtomicFileWriter::write() not enforcing $confine_to_directory - a target outside of
     * it (not necessarily via a symlink; a plain path escape is enough) must be rejected before
     * anything is written.
     */
    public function testAtomicFileWriterRejectsATargetOutsideTheConfinedDirectory(): void
    {
        $outside = $this->decoy_directory . '/outside.po';

        try {
            AtomicFileWriter::write($outside, 'should not be written', $this->client_data_dir);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not resolve to a path below', $e->getMessage());
        }

        $this->assertFileDoesNotExist($outside);
    }
}
