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

namespace ILIAS\Language\Tests\ComponentTranslation\Catalog;

use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use PHPUnit\Framework\TestCase;

/**
 * TranslationEntry and the in-memory part of TranslationCatalog (add()/find()/getEntries()). Also
 * pins the gettext/gettext behaviour the callers rely on or have to live with (shared message
 * objects, comments stored as a set, sorted flags).
 */
class TranslationEntryTest extends TestCase
{
    private static function entry(?string $context, string $id, string $translation = ''): TranslationEntry
    {
        $entry = new TranslationEntry($context, $id);
        $entry->translate($translation);

        return $entry;
    }

    /**
     * @return list<array{?string, string, string}>
     */
    private static function describe(TranslationCatalog $catalog): array
    {
        return array_map(
            static fn(TranslationEntry $entry): array => [$entry->getContext(), $entry->getId(), $entry->getTranslation()],
            $catalog->getEntries()
        );
    }

    // --------------------------------------------------------------- entry

    public function testANewEntryIsEmpty(): void
    {
        $entry = new TranslationEntry(null, 'id');

        $this->assertNull($entry->getContext());
        $this->assertSame('id', $entry->getId());
        $this->assertSame('', $entry->getTranslation());
        $this->assertSame([], $entry->getTranslatorComments());
        $this->assertSame([], $entry->getExtractedComments());
        $this->assertSame([], $entry->getFlags());
    }

    public function testTranslateReplacesTheTranslation(): void
    {
        $entry = self::entry('mod', 'id', 'alt');
        $entry->translate('0');

        $this->assertSame('mod', $entry->getContext());
        $this->assertSame('0', $entry->getTranslation());
    }

    /**
     * Flags are deduplicated, kept sorted by the library, an empty flag is ignored and removing an
     * absent one is a no-op.
     */
    public function testFlagsAreSortedDeduplicatedEmptyOnesIgnoredAndRemovable(): void
    {
        $entry = new TranslationEntry('mod', 'id');
        $entry->addFlag('php-format');
        $entry->addFlag('fuzzy');
        $entry->addFlag('fuzzy');
        $entry->addFlag('');

        $this->assertSame(['fuzzy', 'php-format'], $entry->getFlags());

        $entry->removeFlag('fuzzy');
        $entry->removeFlag('not-set');

        $this->assertSame(['php-format'], $entry->getFlags());
        $this->assertFalse($entry->hasFlag('fuzzy'));
        $this->assertTrue($entry->hasFlag('php-format'));
    }

    public function testRemoveTranslatorCommentsStartingWithOnlyRemovesMatchingCommentsAndKeepsTheOrder(): void
    {
        $entry = new TranslationEntry('mod', 'id');
        $entry->addTranslatorComment('original: a');
        $entry->addTranslatorComment('note');
        $entry->addTranslatorComment('original: b');
        $entry->addTranslatorComment(' original: indented');
        $entry->addTranslatorComment('original:no space');
        $entry->addTranslatorComment('last');

        $entry->removeTranslatorCommentsStartingWith('original: ');

        $this->assertSame(['note', ' original: indented', 'original:no space', 'last'], $entry->getTranslatorComments());
    }

    /**
     * Characterization of gettext/gettext: comments are a set compared loosely - an equal comment,
     * but also a numerically equal one ("1" == "01" == "1.0"), is not added a second time. The
     * component's own comments ("original: ...", "local_change: ...") are never numeric.
     */
    public function testAnEqualCommentIsNotStoredTwice(): void
    {
        $entry = new TranslationEntry('mod', 'id');
        $entry->addTranslatorComment('note');
        $entry->addTranslatorComment('note');
        $entry->addTranslatorComment('1');
        $entry->addTranslatorComment('01');
        $entry->addTranslatorComment('1.0');
        $entry->addExtractedComment('ext');
        $entry->addExtractedComment('ext');

        $this->assertSame(['note', '1'], $entry->getTranslatorComments());
        $this->assertSame(['ext'], $entry->getExtractedComments());
    }

    public function testSetExtractedCommentsReplacesAllOfThemAsSingleLines(): void
    {
        $entry = new TranslationEntry('mod', 'id');
        $entry->addExtractedComment('old');
        $entry->addTranslatorComment('translator');

        $entry->setExtractedComments(['b', "c\nd", 'a']);

        $this->assertSame(['b', 'c d', 'a'], $entry->getExtractedComments());
        $this->assertSame(['translator'], $entry->getTranslatorComments(), 'translator comments are separate');

        $entry->setExtractedComments([]);

        $this->assertSame([], $entry->getExtractedComments());
    }

    // ------------------------------------------------------------- catalog

    public function testFindDistinguishesContexts(): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add(self::entry(null, 'id', 'ohne'));
        $catalog->add(self::entry('mod', 'id', 'mod'));

        $this->assertSame('ohne', $catalog->find(null, 'id')?->getTranslation());
        $this->assertSame('mod', $catalog->find('mod', 'id')?->getTranslation());
        $this->assertNull($catalog->find('other', 'id'));
        $this->assertNull($catalog->find('mod', 'ID'));
    }

    public function testGetEntriesKeepsTheInsertionOrder(): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add(self::entry('mod', 'b', 'B'));
        $catalog->add(self::entry('mod', '10', 'zehn'));
        $catalog->add(self::entry('mod', 'a', 'A'));

        $this->assertSame([['mod', 'b', 'B'], ['mod', '10', 'zehn'], ['mod', 'a', 'A']], self::describe($catalog));
    }

    public function testAddReplacesAnEntryWithTheSameContextAndIdKeepingItsPosition(): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add(self::entry('mod', 'a', 'alt'));
        $catalog->add(self::entry('mod', 'b', 'B'));
        $catalog->add(self::entry('other', 'a', 'fremd'));

        $catalog->add(self::entry('mod', 'a', 'neu'));

        $this->assertSame([['mod', 'a', 'neu'], ['mod', 'b', 'B'], ['other', 'a', 'fremd']], self::describe($catalog));
    }

    /**
     * add() does not copy: MigratedLanguageFileSync takes entries of one catalog, changes and adds
     * them to another one - and relies on find() returning the message itself.
     */
    public function testAnAddedEntryIsSharedNotCopied(): void
    {
        $first = new TranslationCatalog();
        $second = new TranslationCatalog();
        $entry = self::entry('mod', 'a', 'alt');
        $first->add($entry);
        $second->add($entry);

        $entry->translate('nach add');
        $first->find('mod', 'a')?->addFlag('fuzzy');

        $this->assertSame('nach add', $first->find('mod', 'a')?->getTranslation());
        $this->assertSame('nach add', $second->find('mod', 'a')?->getTranslation());
        $this->assertTrue($entry->hasFlag('fuzzy'));
        $this->assertTrue($second->find('mod', 'a')?->hasFlag('fuzzy'));
    }

    /**
     * Two catalogs read from the same file are independent (sync() reads the shipped file twice
     * for exactly that reason: one copy is modified, the other one is the comparison baseline).
     */
    public function testCatalogsReadFromTheSameContentAreIndependent(): void
    {
        $po = "msgctxt \"mod\"\nmsgid \"a\"\nmsgstr \"shipped\"\n";
        $baseline = TranslationCatalog::fromPoString($po);
        $modified = TranslationCatalog::fromPoString($po);

        $modified->find('mod', 'a')?->translate('changed');

        $this->assertSame('shipped', $baseline->find('mod', 'a')?->getTranslation());
    }
}
