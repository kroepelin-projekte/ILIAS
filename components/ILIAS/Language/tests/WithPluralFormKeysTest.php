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

use PHPUnit\Framework\TestCase;

/**
 * ilObjLanguageExt::withPluralFormKeys() (private, static, pure - no DB/file I/O: it only maps keys
 * within the given $shipped_migrated['values']/['modules'] arrays) - the one place that turns a plain
 * plural identifier ("ptest#:#item") into its default form key ("ptest#:#item [1]") for every input
 * saveValues() accepts (saved values, remarks, an imported file's values/comments).
 */
class WithPluralFormKeysTest extends TestCase
{
    private const string SEPARATOR = '#:#';

    /**
     * @param array<string, mixed> $entries
     * @param array{values: array<string, string>, modules: list<string>} $shipped_migrated
     * @return array<string, mixed>
     */
    private function call(array $entries, array $shipped_migrated): array
    {
        $method = new ReflectionMethod(ilObjLanguageExt::class, 'withPluralFormKeys');

        return $method->invoke(null, $entries, $shipped_migrated, self::SEPARATOR);
    }

    private const SHIPPED_PLURAL = [
        'values' => ['ptest#:#item [0]' => 'Eintrag', 'ptest#:#item [1]' => 'Einträge'],
        'modules' => ['ptest'],
    ];

    public function testMapsThePlainIdentifierOfAPluralMessageToItsDefaultFormKey(): void
    {
        $result = $this->call(['ptest#:#item' => 'X'], self::SHIPPED_PLURAL);

        $this->assertSame(['ptest#:#item [1]' => 'X'], $result);
    }

    /**
     * A key of a module that is not migrated at all (not listed in $shipped_migrated['modules']) is
     * never touched, even if it happens to look like a plural identifier for another module.
     */
    public function testLeavesAKeyOfANonMigratedModuleUnchanged(): void
    {
        $entries = ['other#:#item' => 'X'];

        $this->assertSame($entries, $this->call($entries, self::SHIPPED_PLURAL));
    }

    /**
     * A singular key configured alongside a plural message (e.g. "item_singular") is an ordinary
     * identifier of its own - defaultFormKeyOf() returns null for it, so it is left unchanged.
     */
    public function testLeavesASingularKeyUnchanged(): void
    {
        $shipped = self::SHIPPED_PLURAL;
        $shipped['values']['ptest#:#item_singular'] = 'Ein Eintrag';
        $entries = ['ptest#:#item_singular' => 'X'];

        $this->assertSame($entries, $this->call($entries, $shipped));
    }

    /**
     * An already-explicit form key is left exactly as it is - it is not itself the plain identifier
     * of a plural message, so defaultFormKeyOf() returns null for it too.
     */
    public function testLeavesAnExplicitFormKeyUnchanged(): void
    {
        $entries = ['ptest#:#item [0]' => 'X', 'ptest#:#item [1]' => 'Y'];

        $this->assertSame($entries, $this->call($entries, self::SHIPPED_PLURAL));
    }

    /**
     * An ordinary (non-plural) identifier of a migrated module is left unchanged.
     */
    public function testLeavesAnOrdinaryIdentifierOfAMigratedModuleUnchanged(): void
    {
        $entries = ['ptest#:#greeting' => 'Hallo'];

        $this->assertSame($entries, $this->call($entries, self::SHIPPED_PLURAL));
    }

    /**
     * No migrated module at all - the whole map is returned completely untouched (the documented fast
     * path).
     */
    public function testReturnsEntriesUnchangedWhenNoModuleIsMigratedAtAll(): void
    {
        $entries = ['ptest#:#item' => 'X', 'malformed-key-without-separator' => 'Y'];

        $this->assertSame($entries, $this->call($entries, ['values' => [], 'modules' => []]));
    }

    /**
     * A key that does not split into exactly module/topic (e.g. no separator at all) is left as is,
     * even for a migrated module.
     */
    public function testLeavesAMalformedKeyWithoutASeparatorUnchanged(): void
    {
        $entries = ['not_a_module_topic_pair' => 'X'];

        $this->assertSame($entries, $this->call($entries, self::SHIPPED_PLURAL));
    }

    /**
     * Order matters: a later entry that maps (or already is) the same resolved key overwrites an
     * earlier one - exactly like an ordinary PHP array literal with a duplicate key.
     */
    public function testALaterEntryMappingToTheSameFormKeyOverwritesAnEarlierOne(): void
    {
        $result = $this->call(
            ['ptest#:#item [1]' => 'Von Formzeile', 'ptest#:#item' => 'Von Plain-Key'],
            self::SHIPPED_PLURAL
        );

        $this->assertSame(['ptest#:#item [1]' => 'Von Plain-Key'], $result);
    }

    public function testAnEarlierPlainKeyIsOverwrittenByALaterExplicitFormKey(): void
    {
        $result = $this->call(
            ['ptest#:#item' => 'Von Plain-Key', 'ptest#:#item [1]' => 'Von Formzeile'],
            self::SHIPPED_PLURAL
        );

        $this->assertSame(['ptest#:#item [1]' => 'Von Formzeile'], $result);
    }

    /**
     * Values of any type (not just string) round-trip unchanged - withPluralFormKeys() is also used
     * for the remarks map, which callers may pass booleans/null through.
     */
    public function testPreservesNonStringValueTypes(): void
    {
        $result = $this->call(['ptest#:#item' => null], self::SHIPPED_PLURAL);

        $this->assertNull($result['ptest#:#item [1]']);
    }
}
