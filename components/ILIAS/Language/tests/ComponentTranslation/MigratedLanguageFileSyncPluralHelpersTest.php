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

use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use PHPUnit\Framework\TestCase;

/**
 * The pure identifier=>value helpers MigratedLanguageFileSync uses to fold a plural message's forms
 * (keyed via PluralFormKey, "<identifier> [<form>]") back to a single database row and back again -
 * the one place that decides what lng_data ends up holding for a plural message, and therefore the
 * highest-risk part of the plural pilot for silent data loss/corruption. Exercised directly (no I/O,
 * no PluralForms header parsing involved - the "shipped_entries" fixtures below only need to carry
 * the plural message's form keys, exactly what MigratedLanguageFileSync::flatModuleValues() would
 * have produced).
 */
class MigratedLanguageFileSyncPluralHelpersTest extends TestCase
{
    // ------------------------------------------------------------- collapsePluralForms()

    public function testCollapsesEveryFormToTheIdentifierWithTheDefaultFormsValue(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge NEU'];

        $this->assertSame(['item' => 'Einträge NEU'], MigratedLanguageFileSync::collapsePluralForms($entries, $shipped));
    }

    /**
     * A form missing from $entries counts with its shipped value - collapsePluralForms() must not
     * silently treat it as empty (which would corrupt the default value with a partial submission).
     */
    public function testAFormMissingFromEntriesFallsBackToItsShippedValue(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item [1]' => 'Einträge NEU']; // form 0 not submitted at all

        $this->assertSame(['item' => 'Einträge NEU'], MigratedLanguageFileSync::collapsePluralForms($entries, $shipped));
    }

    /**
     * Every entry is placed at the position of its identifier's first form key - so a caller
     * iterating the result in order sees the plural message where its first form used to be, not at
     * the end.
     */
    public function testThePluralIdentifierIsPlacedAtThePositionOfItsFirstFormKey(): void
    {
        $shipped = ['before' => 'A', 'item [0]' => 'Eintrag', 'item [1]' => 'Einträge', 'after' => 'B'];

        $this->assertSame(
            ['before', 'item', 'after'],
            array_keys(MigratedLanguageFileSync::collapsePluralForms($shipped, $shipped))
        );
    }

    /**
     * A form index the shipped message does not declare (e.g. "item [5]" when nplurals=2, a stray/
     * corrupt submission) is ignored - it must not create a phantom identifier, crash, or affect the
     * regular forms' collapsed value.
     */
    public function testAFormIndexOutsideTheShippedFormCountIsIgnored(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge NEU', 'item [5]' => 'Geister-Form'];

        $collapsed = MigratedLanguageFileSync::collapsePluralForms($entries, $shipped);

        $this->assertSame(['item' => 'Einträge NEU'], $collapsed);
    }

    /**
     * A plain value under the plural identifier itself alongside form keys (a legacy row not yet
     * mapped by mapLegacyPluralValues(), or stray input) loses to the forms - they are what a plural
     * message actually contains.
     */
    public function testAPlainValueNextToFormsIsDroppedInFavourOfTheForms(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item' => 'Veraltet', 'item [0]' => 'Eintrag', 'item [1]' => 'Einträge NEU'];

        $this->assertSame(['item' => 'Einträge NEU'], MigratedLanguageFileSync::collapsePluralForms($entries, $shipped));
    }

    public function testEntriesUnrelatedToAnyPluralMessageAreKeptAsIs(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge', 'plain' => 'X'];
        $entries = ['plain' => 'Y', 'other' => 'Z'];

        $this->assertSame(['plain' => 'Y', 'other' => 'Z'], MigratedLanguageFileSync::collapsePluralForms($entries, $shipped));
    }

    public function testWithoutAnyPluralMessageInShippedEntriesIsAnIdentity(): void
    {
        $shipped = ['plain' => 'X'];
        $entries = ['plain' => 'Y', 'other [0]' => 'looks like a form but is not one'];

        $this->assertSame($entries, MigratedLanguageFileSync::collapsePluralForms($entries, $shipped));
    }

    /**
     * An identifier that shipped its own entry AND form keys (contradictory shipped data) is not
     * treated as a plural message at all - pluralFormCounts()' own guard.
     */
    public function testAnIdentifierShippedBothAsItsOwnEntryAndWithFormKeysIsNotTreatedAsPlural(): void
    {
        $shipped = ['item' => 'Eigener Wert', 'item [0]' => 'Form 0', 'item [1]' => 'Form 1'];

        $this->assertSame($shipped, MigratedLanguageFileSync::collapsePluralForms($shipped, $shipped));
    }

    // ------------------------------------------------------------- mapLegacyPluralValues()

    /**
     * A plain value under a plural identifier (a local change recorded before the module had
     * plurals, or a value read back from lng_data, which only ever holds the identifier) becomes the
     * value of its default form - never silently dropped nor kept under the bare identifier.
     */
    public function testAPlainValueOfAPluralIdentifierBecomesItsDefaultForm(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item' => 'Alter DB-Wert'];

        $this->assertSame(['item [1]' => 'Alter DB-Wert'], MigratedLanguageFileSync::mapLegacyPluralValues($entries, $shipped));
    }

    public function testAPlainValueOfASingleFormLanguagesPluralIdentifierBecomesFormZero(): void
    {
        $shipped = ['item [0]' => 'Eintrag']; // nplurals=1
        $entries = ['item' => 'Alter DB-Wert'];

        $this->assertSame(['item [0]' => 'Alter DB-Wert'], MigratedLanguageFileSync::mapLegacyPluralValues($entries, $shipped));
    }

    /**
     * The default form key already present (a real submission of that form) wins - the plain,
     * legacy value is dropped rather than overwriting it.
     */
    public function testAPlainValueIsDroppedWhenItsDefaultFormKeyIsAlreadyPresent(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item' => 'Alter DB-Wert', 'item [1]' => 'Neue Form 1'];

        $this->assertSame(['item [1]' => 'Neue Form 1'], MigratedLanguageFileSync::mapLegacyPluralValues($entries, $shipped));
    }

    /**
     * A non-default form key already present must not stop the plain value from being mapped to the
     * (still absent) default form key.
     */
    public function testAPlainValueIsStillMappedWhenOnlyANonDefaultFormIsAlreadyPresent(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item' => 'Alter DB-Wert', 'item [0]' => 'Neue Form 0'];

        $this->assertSame(
            ['item [0]' => 'Neue Form 0', 'item [1]' => 'Alter DB-Wert'],
            MigratedLanguageFileSync::mapLegacyPluralValues($entries, $shipped)
        );
    }

    public function testEntriesWithNoPlainPluralIdentifierAreUnaffected(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['plain' => 'X', 'item [0]' => 'A', 'item [1]' => 'B'];

        $this->assertSame($entries, MigratedLanguageFileSync::mapLegacyPluralValues($entries, $shipped));
    }

    /**
     * Values of any type (not just string) round-trip unchanged - mapLegacyPluralValues() is also
     * used with the boolean "is this key a local change" map of loadOverlay()/resolveMigratedModule().
     */
    public function testPreservesNonStringValueTypes(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];
        $entries = ['item' => true];

        $this->assertSame(['item [1]' => true], MigratedLanguageFileSync::mapLegacyPluralValues($entries, $shipped));
    }

    // ------------------------------------------------------------- pluralMessageOf()

    public function testPluralMessageOfResolvesAFormKeyToItsIdentifier(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];

        $this->assertSame('item', MigratedLanguageFileSync::pluralMessageOf('item [1]', $shipped));
    }

    public function testPluralMessageOfResolvesTheBareIdentifierToo(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge'];

        $this->assertSame('item', MigratedLanguageFileSync::pluralMessageOf('item', $shipped));
    }

    public function testPluralMessageOfIsNullForAnOrdinaryIdentifier(): void
    {
        $shipped = ['item [0]' => 'Eintrag', 'item [1]' => 'Einträge', 'plain' => 'X'];

        $this->assertNull(MigratedLanguageFileSync::pluralMessageOf('plain', $shipped));
    }

    public function testPluralMessageOfIsNullForAKeyThatMerelyLooksLikeAFormKey(): void
    {
        $shipped = ['plain [0]' => 'X']; // shipped as its own entry, not via other forms
        $shipped['plain'] = 'irrelevant';

        $this->assertNull(MigratedLanguageFileSync::pluralMessageOf('plain [0]', $shipped));
    }

    public function testPluralMessageOfIsNullForAnUnknownIdentifier(): void
    {
        $this->assertNull(MigratedLanguageFileSync::pluralMessageOf('unknown [0]', []));
        $this->assertNull(MigratedLanguageFileSync::pluralMessageOf('unknown', []));
    }
}
