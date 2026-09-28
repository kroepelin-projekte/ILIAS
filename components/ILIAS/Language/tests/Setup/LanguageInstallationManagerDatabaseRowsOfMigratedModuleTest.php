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

use ILIAS\Language\Setup\LanguageInstallationManager;
use PHPUnit\Framework\TestCase;

/**
 * LanguageInstallationManager::databaseRowsOfMigratedModule() (private, static, pure - no DB/Setup
 * fixtures needed): folds the buffered per-form lng_data rows of a migrated plural message (see
 * $add_row/$migrated_rows in insertLanguageForInstallation()) into the single row the database must
 * actually end up with - the identifier, with the default form's value, the first non-null remarks of
 * its forms, and local_change only when that merged default-form value actually differs from the
 * shipped default (never merely because some form's buffered row happens to carry a stale
 * local_change timestamp). The highest-risk step of the reinstall/update path for a plural module:
 * getting this wrong means either a stale/wrong value under the message's identifier, a bogus local
 * change marker, or a duplicate row per form.
 */
class LanguageInstallationManagerDatabaseRowsOfMigratedModuleTest extends TestCase
{
    /**
     * @param array<string, array{0: string, 1: ?string, 2: ?string}> $rows
     * @param array<string, string> $final_entries
     * @param array<string, string> $shipped_entries
     * @return array<string, array{0: string, 1: ?string, 2: ?string}>
     */
    private function call(array $rows, array $final_entries, array $shipped_entries): array
    {
        $method = (new ReflectionClass(LanguageInstallationManager::class))->getMethod('databaseRowsOfMigratedModule');

        return $method->invoke(null, $rows, $final_entries, $shipped_entries);
    }

    private const SHIPPED = [
        'poll_population [0]' => 'Eine Stimmabgabe',
        'poll_population [1]' => '%s Stimmabgaben',
        'poll_population_singular' => 'Eine Stimmabgabe',
    ];

    /**
     * Two buffered form rows fold into one row under the plain identifier, with the default form's
     * (msgstr[1], count >= 2) value of the module's final content - exactly what the database held
     * for "poll_population" before the module ever had plurals.
     */
    public function testTwoFormRowsFoldIntoOneRowWithTheDefaultFormsValue(): void
    {
        $rows = [
            'poll_population [0]' => ['Eine Stimmabgabe', null, null],
            'poll_population [1]' => ['%s NEUE Stimmabgaben', null, null],
        ];
        $final_entries = [
            'poll_population [0]' => 'Eine Stimmabgabe',
            'poll_population [1]' => '%s NEUE Stimmabgaben',
        ];

        $result = $this->call($rows, $final_entries, self::SHIPPED);

        $this->assertSame(['poll_population' => ['%s NEUE Stimmabgaben', null, null]], $result);
    }

    /**
     * A plain row of an identifier that is not a plural message of the shipped state (here:
     * "poll_population_singular", the singular key configured alongside "poll_population" - shipped
     * as its own entry, not as form keys, see pluralMessageOf()) is kept untouched, one row per key.
     */
    public function testAPlainRowOfAnUnrelatedIdentifierIsKeptUnchanged(): void
    {
        $rows = ['poll_population_singular' => ['Eine Stimmabgabe', '2024-01-01 00:00:00', 'Anmerkung']];

        $result = $this->call($rows, ['poll_population_singular' => 'Eine Stimmabgabe'], self::SHIPPED);

        $this->assertSame(
            ['poll_population_singular' => ['Eine Stimmabgabe', '2024-01-01 00:00:00', 'Anmerkung']],
            $result
        );
    }

    /**
     * local_change is gated on the merged (default-form) VALUE actually differing from the shipped
     * default - not merely on some row carrying a non-null local_change timestamp: unchanged rows
     * whose buffered row happens to carry a stale local_change must not resurrect it. Only the
     * remarks are kept independently of that (see the next tests) - the first non-null one, here from
     * the second row, since the first row's is null.
     */
    public function testLocalChangeIsNullWhenTheMergedDefaultFormValueMatchesTheShippedOne(): void
    {
        $rows = [
            'poll_population [0]' => ['Eine Stimmabgabe', null, null],
            'poll_population [1]' => ['%s Stimmabgaben', '2024-01-01 00:00:00', 'lokal geändert'],
        ];
        // identical to self::SHIPPED - the message did not actually change
        $final_entries = ['poll_population [0]' => 'Eine Stimmabgabe', 'poll_population [1]' => '%s Stimmabgaben'];

        $result = $this->call($rows, $final_entries, self::SHIPPED);

        $this->assertSame(
            ['poll_population' => ['%s Stimmabgaben', null, 'lokal geändert']],
            $result
        );
    }

    /**
     * The first non-null local_change/remarks among a plural message's buffered form rows wins once
     * the merged value actually differs from the shipped one - a later row's null must not erase what
     * an earlier one already set.
     */
    public function testTheFirstNonNullLocalChangeAndRemarksOfTheFormRowsAreKeptWhenTheValueActuallyChanged(): void
    {
        $rows = [
            'poll_population [0]' => ['Eine Stimmabgabe', null, null],
            'poll_population [1]' => ['%s NEUE Stimmabgaben', '2024-01-01 00:00:00', 'lokal geändert'],
        ];
        $final_entries = ['poll_population [0]' => 'Eine Stimmabgabe', 'poll_population [1]' => '%s NEUE Stimmabgaben'];

        $result = $this->call($rows, $final_entries, self::SHIPPED);

        $this->assertSame(
            ['poll_population' => ['%s NEUE Stimmabgaben', '2024-01-01 00:00:00', 'lokal geändert']],
            $result
        );
    }

    /**
     * Same as above, but the local_change-carrying row is processed FIRST - it must still win, not
     * be overwritten by the later row's null.
     */
    public function testAnEarlierNonNullLocalChangeSurvivesALaterRowsNullOnesWhenTheValueActuallyChanged(): void
    {
        $rows = [
            'poll_population [1]' => ['%s NEUE Stimmabgaben', '2024-01-01 00:00:00', 'lokal geändert'],
            'poll_population [0]' => ['Eine Stimmabgabe', null, null],
        ];
        $final_entries = ['poll_population [0]' => 'Eine Stimmabgabe', 'poll_population [1]' => '%s NEUE Stimmabgaben'];

        $result = $this->call($rows, $final_entries, self::SHIPPED);

        $this->assertSame(
            ['poll_population' => ['%s NEUE Stimmabgaben', '2024-01-01 00:00:00', 'lokal geändert']],
            $result
        );
    }

    /**
     * A buffered form row whose message has no value at all in the final content (collapsePluralForms()
     * of $final_entries then has no entry for it) is dropped instead of writing a row with a bogus or
     * empty value - the message simply is not part of the module's final content.
     */
    public function testFormRowsWithNoValueInTheFinalContentAreDroppedEntirely(): void
    {
        $rows = [
            'poll_population [0]' => ['Eine Stimmabgabe', null, null],
            'poll_population [1]' => ['%s Stimmabgaben', null, null],
            'poll_population_singular' => ['Eine Stimmabgabe', null, null],
        ];
        // final content no longer mentions "poll_population" at all
        $final_entries = ['poll_population_singular' => 'Eine Stimmabgabe'];

        $result = $this->call($rows, $final_entries, self::SHIPPED);

        $this->assertArrayNotHasKey('poll_population', $result);
        $this->assertSame(['poll_population_singular' => ['Eine Stimmabgabe', null, null]], $result);
    }

    /**
     * A single-form language (nplurals=1): the message still folds into one row, keyed by its
     * identifier, with its only form's value - not left as a form-keyed row.
     */
    public function testASingleFormLanguageStillFoldsIntoOneRow(): void
    {
        $shipped = ['poll_population [0]' => 'ある投票'];
        $rows = ['poll_population [0]' => ['ある投票NEU', null, null]];
        $final_entries = ['poll_population [0]' => 'ある投票NEU'];

        $result = $this->call($rows, $final_entries, $shipped);

        $this->assertSame(['poll_population' => ['ある投票NEU', null, null]], $result);
    }
}
