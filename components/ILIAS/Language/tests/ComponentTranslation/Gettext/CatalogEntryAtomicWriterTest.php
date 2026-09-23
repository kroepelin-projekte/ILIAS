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
use ILIAS\Language\ComponentTranslation\Gettext\Catalog;
use ILIAS\Language\ComponentTranslation\Gettext\Entry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The small data classes Catalog/Entry and AtomicFileWriter.
 */
class CatalogEntryAtomicWriterTest extends TestCase
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

    // ------------------------------------------------------------- Catalog

    public function testFindDistinguishesContextNullFromEmptyContextAndFromOtherContexts(): void
    {
        $catalog = new Catalog();
        $catalog->add(new Entry(null, 'id'));

        $this->assertNotNull($catalog->find(null, 'id'));
        $this->assertNull($catalog->find('', 'id'));
        $this->assertNull($catalog->find('mod', 'id'));
        $this->assertSame('id', Catalog::key(null, 'id'));
        $this->assertSame("mod\x04id", Catalog::key('mod', 'id'));
    }

    public function testAddReplacesAnEntryWithTheSameContextAndIdKeepingItsPosition(): void
    {
        $catalog = new Catalog();
        $first = new Entry('mod', 'a');
        $catalog->add($first);
        $catalog->add(new Entry('mod', 'b'));
        $replacement = new Entry('mod', 'a');
        $replacement->translate('neu');
        $catalog->add($replacement);

        $this->assertSame([$replacement, $catalog->find('mod', 'b')], $catalog->getEntries());
    }

    public function testRemoveOnlyRemovesTheMatchingEntry(): void
    {
        $catalog = new Catalog();
        $catalog->add(new Entry('mod', 'a'));
        $catalog->add(new Entry('other', 'a'));

        $catalog->remove(new Entry('mod', 'a'));

        $this->assertNull($catalog->find('mod', 'a'));
        $this->assertNotNull($catalog->find('other', 'a'));
    }

    public function testHeaderFlagsAreDeduplicatedAndEmptyOnesIgnored(): void
    {
        $catalog = new Catalog();
        $catalog->addHeaderFlag('fuzzy');
        $catalog->addHeaderFlag('fuzzy');
        $catalog->addHeaderFlag('');

        $this->assertSame(['fuzzy'], $catalog->getHeaderFlags());
        $this->assertNull($catalog->getHeader('Language'));
    }

    // --------------------------------------------------------------- Entry

    public function testFlagsAreDeduplicatedEmptyOnesIgnoredAndRemovable(): void
    {
        $entry = new Entry('mod', 'id');
        $entry->addFlag('fuzzy');
        $entry->addFlag('php-format');
        $entry->addFlag('fuzzy');
        $entry->addFlag('');

        $this->assertSame(['fuzzy', 'php-format'], $entry->getFlags());

        $entry->removeFlag('fuzzy');
        $entry->removeFlag('not-set');

        $this->assertSame(['php-format'], $entry->getFlags());
        $this->assertFalse($entry->hasFlag('fuzzy'));
        $this->assertTrue($entry->hasFlag('php-format'));
    }

    public function testRemoveTranslatorCommentsStartingWithOnlyRemovesMatchingComments(): void
    {
        $entry = new Entry('mod', 'id');
        $entry->addTranslatorComment('original: a');
        $entry->addTranslatorComment('note');
        $entry->addTranslatorComment('original: b');
        $entry->addTranslatorComment(' original: indented');

        $entry->removeTranslatorCommentsStartingWith('original: ');

        $this->assertSame(['note', ' original: indented'], $entry->getTranslatorComments());
    }

    public function testPluralTranslationsAreOrderedByIndexAndReplaceable(): void
    {
        $entry = new Entry('mod', 'id');
        $entry->setPluralTranslation(1, 'eins');
        $entry->setPluralTranslation(2, 'zwei');
        $entry->setPluralTranslation(1, 'eins, neu');

        $this->assertSame(['eins, neu', 'zwei'], $entry->getPluralTranslations());
    }

    /**
     * Regression: msgstr[2] set before msgstr[1] used to be renumbered by array_values() and then
     * overwritten. The index n is kept as key n-1; a missing form stays absent.
     */
    public function testPluralTranslationsSetOutOfOrderKeepTheirIndex(): void
    {
        $entry = new Entry('mod', 'id');
        $entry->setPluralTranslation(2, 'zwei');
        $entry->setPluralTranslation(1, 'eins');

        $this->assertSame([0 => 'eins', 1 => 'zwei'], $entry->getPluralTranslations());
    }

    public function testAMissingPluralFormStaysAbsent(): void
    {
        $entry = new Entry('mod', 'id');
        $entry->setPluralTranslation(3, 'drei');
        $entry->setPluralTranslation(1, 'eins');

        $this->assertSame([0 => 'eins', 2 => 'drei'], $entry->getPluralTranslations());
    }

    public function testANewEntryHasAnEmptyTranslationAndNoPlural(): void
    {
        $entry = new Entry(null, 'id');

        $this->assertSame('', $entry->getTranslation());
        $this->assertNull($entry->getPlural());
        $this->assertNull($entry->getContext());
        $this->assertSame([], $entry->getPluralTranslations());
    }

    // ----------------------------------------------------- AtomicFileWriter

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
