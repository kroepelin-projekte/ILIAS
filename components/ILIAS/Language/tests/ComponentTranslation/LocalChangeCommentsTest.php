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

namespace ILIAS\Language\ComponentTranslation;

use Gettext\Translation;
use PHPUnit\Framework\TestCase;

/**
 * Covers the PO/MO pilot's "locally changed" tracking: the DB-backed scheme's
 * lng_data.local_change/remarks, reimplemented as plain gettext translator comments so it works
 * without lng_data at all. Pure unit tests of the comment bookkeeping itself, isolated from
 * ilObjLanguage::syncMigratedLanguageFile() (covered end-to-end in PoMigrationWriteBackTest.php).
 */
class LocalChangeCommentsTest extends TestCase
{
    public function testANewlyMigratedEntryHasNoLocalChangeRightAfterOriginalIsSet(): void
    {
        $translation = Translation::create('demo', 'greeting');
        LocalChangeComments::setOriginal($translation, 'Hallo');

        $this->assertSame('Hallo', LocalChangeComments::getOriginal($translation));
        $this->assertNull(LocalChangeComments::getLocalChange($translation));
    }

    public function testRefreshSetsALocalChangeTimestampWhenTheValueDiffersFromTheOriginal(): void
    {
        $translation = Translation::create('demo', 'greeting');
        LocalChangeComments::setOriginal($translation, 'Hallo');

        $now = new \DateTimeImmutable('2026-09-21T10:00:00Z');
        LocalChangeComments::refresh($translation, 'Hallo', 'Hallo, geändert', $now);

        $this->assertSame('2026-09-21T10:00:00Z', LocalChangeComments::getLocalChange($translation));
    }

    public function testRefreshClearsTheLocalChangeWhenTheValueIsSetBackToTheOriginal(): void
    {
        $translation = Translation::create('demo', 'greeting');
        LocalChangeComments::setOriginal($translation, 'Hallo');
        LocalChangeComments::refresh(
            $translation,
            'Hallo',
            'Hallo, geändert',
            new \DateTimeImmutable('2026-09-21T10:00:00Z')
        );

        LocalChangeComments::refresh(
            $translation,
            'Hallo, geändert',
            'Hallo',
            new \DateTimeImmutable('2026-09-21T14:00:00Z')
        );

        $this->assertNull(LocalChangeComments::getLocalChange($translation));
        // "original" itself must never be touched by refresh()
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($translation));
    }

    public function testRefreshDoesNotBumpAnAlreadyCurrentTimestampOnAnIdempotentResave(): void
    {
        $translation = Translation::create('demo', 'greeting');
        LocalChangeComments::setOriginal($translation, 'Hallo');
        LocalChangeComments::refresh(
            $translation,
            'Hallo',
            'Hallo, geändert',
            new \DateTimeImmutable('2026-09-21T10:00:00Z')
        );

        // same value written again, e.g. an unrelated key in the same module was edited too
        LocalChangeComments::refresh(
            $translation,
            'Hallo, geändert',
            'Hallo, geändert',
            new \DateTimeImmutable('2026-09-21T12:00:00Z')
        );

        $this->assertSame('2026-09-21T10:00:00Z', LocalChangeComments::getLocalChange($translation));
    }

    public function testRefreshUpdatesAnExistingTimestampWhenTheValueChangesAgain(): void
    {
        $translation = Translation::create('demo', 'greeting');
        LocalChangeComments::setOriginal($translation, 'Hallo');
        LocalChangeComments::refresh(
            $translation,
            'Hallo',
            'Hallo, geändert',
            new \DateTimeImmutable('2026-09-21T10:00:00Z')
        );

        LocalChangeComments::refresh(
            $translation,
            'Hallo, geändert',
            'Hallo, nochmal geändert',
            new \DateTimeImmutable('2026-09-21T12:00:00Z')
        );

        $this->assertSame('2026-09-21T12:00:00Z', LocalChangeComments::getLocalChange($translation));
    }

    /**
     * A key that never went through the conversion tool (e.g. added by a later admin edit, not
     * present in the reference .lang file at migration time) has no baseline to compare against.
     * Treated as always locally changed - the DB-backed scheme's replaceLangEntry() does the same for
     * a brand new lng_data row (see class.ilObjLanguageExt.php:437, "isset($db_values[$key]) ... :
     * true").
     */
    public function testRefreshTreatsAMissingOriginalAsAlwaysLocallyChanged(): void
    {
        $translation = Translation::create('demo', 'brand_new_key');

        LocalChangeComments::refresh($translation, '', 'Neuer Wert', new \DateTimeImmutable('2026-09-21T15:00:00Z'));

        $this->assertNull(LocalChangeComments::getOriginal($translation));
        $this->assertSame('2026-09-21T15:00:00Z', LocalChangeComments::getLocalChange($translation));
    }

    /**
     * Gettext\Comments::add() only deduplicates exact string matches, it never replaces an existing
     * entry (see vendor/composer/vendor/gettext/gettext/src/Comments.php) - a naive add() on every
     * refresh() would leave every past timestamp sitting in the file forever. Exactly one
     * "local_change: " line must exist after multiple refreshes.
     */
    public function testRefreshNeverLeavesMoreThanOneLocalChangeCommentBehind(): void
    {
        $translation = Translation::create('demo', 'greeting');
        LocalChangeComments::setOriginal($translation, 'Hallo');

        LocalChangeComments::refresh($translation, 'Hallo', 'A', new \DateTimeImmutable('2026-09-21T10:00:00Z'));
        LocalChangeComments::refresh($translation, 'A', 'B', new \DateTimeImmutable('2026-09-21T11:00:00Z'));
        LocalChangeComments::refresh($translation, 'B', 'C', new \DateTimeImmutable('2026-09-21T12:00:00Z'));

        $localChangeComments = array_values(array_filter(
            $translation->getComments()->toArray(),
            static fn(string $comment): bool => str_starts_with($comment, 'local_change: ')
        ));

        $this->assertCount(1, $localChangeComments);
        $this->assertSame('local_change: 2026-09-21T12:00:00Z', $localChangeComments[0]);
    }

    public function testEncodesANewlineInTheOriginalValueSoItCannotCorruptThePoCommentLine(): void
    {
        $translation = Translation::create('demo', 'greeting');
        LocalChangeComments::setOriginal($translation, "Zeile eins\nZeile zwei");

        $this->assertSame('Zeile eins Zeile zwei', LocalChangeComments::getOriginal($translation));
    }
}
