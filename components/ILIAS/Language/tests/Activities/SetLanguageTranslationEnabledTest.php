<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with
 * the source code, too.
 *
 *********************************************************************/

declare(strict_types=1);

namespace ILIAS\Language\Activities;

use ILIAS\Administration\Setting;
use ILIAS\Component\Activities\ActivityType;
use ILIAS\Data\Description\Factory as DescriptionFactory;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\Refinery\String\Group as StringGroup;
use ILIAS\Refinery\String\MarkdownFormattingToHTML;
use ILIAS\UI\Factory as UIFactory;
use ilLanguageBaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SetLanguageTranslationEnabled is, like its sibling
 * SetLanguageDetectionEnabled, backed by a single \ILIAS\Administration\Setting
 * collaborator - a plain interface, mockable directly, never a real
 * \ilSetting/database connection. Unlike SetLanguageDetectionEnabled, this
 * Activity is parameterised by a language_key (the setting written/read is
 * "lang_translate_<key>", not a single fixed key), and perform() itself
 * (not just normalizeParameters()) validates its $parameters array and
 * computes a `changed` flag by comparing the requested value against the
 * *currently stored* one (read via a falsy (bool) cast, exactly mirroring
 * `ilObjLanguageAccess::_checkTranslate()` - see the class docblock). That
 * comparison, and the "write only if changed" behaviour it drives, is the
 * most important regression surface in this file: a mutant that always
 * writes (or that inverts the comparison) must be caught here.
 *
 * The second most important regression this file guards is the same one as
 * SetLanguageDetectionEnabledTest: the enforced "write" RBAC check in
 * isAllowedToPerform()/maybePerformAs() must actually prevent both the
 * outcome *and* the side effect (no write to Settings) when denied - see
 * this class's own docblock for why this does NOT, in this particular case,
 * close a pre-existing gap (unlike AddLanguageEntry/SetLanguageDetectionEnabled).
 */
class SetLanguageTranslationEnabledTest extends ilLanguageBaseTestCase
{
    // -----------------------------------------------------------------
    // getType() / getName()
    // -----------------------------------------------------------------

    public function testGetTypeIsCommand(): void
    {
        $activity = $this->createActivity();

        $this->assertSame(ActivityType::Command, $activity->getType());
    }

    public function testGetNameIsTheFullyQualifiedClassName(): void
    {
        $activity = $this->createActivity();

        $this->assertSame(SetLanguageTranslationEnabled::class, (string) $activity->getName());
    }

    // -----------------------------------------------------------------
    // isAllowedToPerform()
    // -----------------------------------------------------------------

    public function testIsAllowedToPerformDelegatesToRbacSystemWithWriteAndTheConfiguredRefId(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->with(6, 'write', 42)
            ->willReturn(true);

        $activity = $this->createActivity(rbac_system: $rbac, language_folder_ref_id: 42);

        $this->assertTrue($activity->isAllowedToPerform(6, ['language_key' => 'de', 'enabled' => true]));
    }

    public function testIsAllowedToPerformReturnsFalseWhenRbacDenies(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(false);

        $activity = $this->createActivity(rbac_system: $rbac);

        $this->assertFalse($activity->isAllowedToPerform(6, ['language_key' => 'de', 'enabled' => true]));
    }

    /**
     * isAllowedToPerform() must be a pure permission check: it must never
     * touch the Setting collaborator (and therefore never read/write
     * "lang_translate_*") - unlike perform(), which is the only place
     * allowed to have side effects.
     */
    public function testIsAllowedToPerformNeverTouchesSettings(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity(rbac_system: $rbac, settings: $settings);

        $this->assertTrue($activity->isAllowedToPerform(6, ['language_key' => 'de', 'enabled' => true]));
    }

    // -----------------------------------------------------------------
    // perform() - the changed/unchanged decision
    // -----------------------------------------------------------------

    /**
     * Core business rule (see class docblock): perform() must read the
     * *current* value of "lang_translate_<key>" via a falsy check - exactly
     * the same interpretation ilObjLanguageAccess::_checkTranslate() applies
     * - and only write when the requested value actually differs. Every
     * combination of a realistic stored raw value ("0", "", "1") and a
     * requested boolean is exercised so that both an accidental "always
     * write" and an accidental "never write" mutant are caught, along with
     * an inverted `!==` -> `===` comparison mutant.
     */
    public static function performChangedDecisionProvider(): array
    {
        return [
            'stored "0", request true -> changed, write "1"' => ['0', true, true, '1'],
            'stored "0", request false -> unchanged, no write' => ['0', false, false, null],
            'stored "", request true -> changed, write "1" (falsy like "0")' => ['', true, true, '1'],
            'stored "", request false -> unchanged, no write (falsy like "0")' => ['', false, false, null],
            'stored "1", request true -> unchanged, no write' => ['1', true, false, null],
            'stored "1", request false -> changed, write "0"' => ['1', false, true, '0'],
        ];
    }

    #[DataProvider('performChangedDecisionProvider')]
    public function testPerformComparesRequestedValueAgainstFalsyCastOfCurrentlyStoredValue(
        string $stored_value,
        bool $requested_enabled,
        bool $expected_changed,
        ?string $expected_written_value
    ): void {
        $settings = $this->createMock(Setting::class);
        $settings->method('get')
            ->with('lang_translate_de', '0')
            ->willReturn($stored_value);

        if ($expected_written_value === null) {
            $settings->expects($this->never())->method('set');
        } else {
            $settings->expects($this->once())
                ->method('set')
                ->with('lang_translate_de', $expected_written_value);
        }

        $result = $this->createActivity(settings: $settings)
            ->perform(['language_key' => 'de', 'enabled' => $requested_enabled]);

        $this->assertSame(
            ['language_key' => 'de', 'enabled' => $requested_enabled, 'changed' => $expected_changed],
            $result
        );
    }

    /**
     * get() must be called with the correct per-language key
     * ("lang_translate_" . language_key) and the documented default '0' -
     * a mutant that drops the default, or concatenates the key in the wrong
     * order/without the separator, must be caught here.
     */
    public function testPerformReadsTheCorrectPerLanguageSettingKeyWithDefaultZero(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())
            ->method('get')
            ->with('lang_translate_fr', '0')
            ->willReturn('0');

        $this->createActivity(settings: $settings)->perform(['language_key' => 'fr', 'enabled' => false]);
    }

    /**
     * language_key must be trimmed before being used to build the setting
     * key and before being returned - mirroring toNonEmptyString()'s
     * behaviour used by normalizeParameters(), but exercised here directly
     * through perform() since perform() re-implements its own trim().
     */
    public function testPerformTrimsLanguageKeyForBothTheSettingKeyAndTheReturnedValue(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->method('get')->with('lang_translate_de', '0')->willReturn('0');
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '1');

        $result = $this->createActivity(settings: $settings)
            ->perform(['language_key' => '  de  ', 'enabled' => true]);

        $this->assertSame('de', $result['language_key']);
    }

    // -----------------------------------------------------------------
    // perform() - parameter validation
    // -----------------------------------------------------------------

    public static function invalidPerformParametersProvider(): array
    {
        return [
            'non-array parameters' => ['not-an-array'],
            'empty array' => [[]],
            'missing language_key' => [['enabled' => true]],
            'non-string language_key' => [['language_key' => 42, 'enabled' => true]],
            'empty string language_key' => [['language_key' => '', 'enabled' => true]],
            'whitespace-only language_key' => [['language_key' => '   ', 'enabled' => true]],
            'missing enabled' => [['language_key' => 'de']],
            'non-bool enabled: string "1"' => [['language_key' => 'de', 'enabled' => '1']],
            'non-bool enabled: string "true"' => [['language_key' => 'de', 'enabled' => 'true']],
            'non-bool enabled: string ""' => [['language_key' => 'de', 'enabled' => '']],
            'non-bool enabled: int 1' => [['language_key' => 'de', 'enabled' => 1]],
            'non-bool enabled: int 0' => [['language_key' => 'de', 'enabled' => 0]],
            'non-bool enabled: float 1.0' => [['language_key' => 'de', 'enabled' => 1.0]],
            'non-bool enabled: null' => [['language_key' => 'de', 'enabled' => null]],
            'non-bool enabled: array' => [['language_key' => 'de', 'enabled' => [true]]],
        ];
    }

    #[DataProvider('invalidPerformParametersProvider')]
    public function testPerformRejectsInvalidOrIncompleteParametersWithoutTouchingSettings(mixed $parameters): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(settings: $settings)->perform($parameters);
    }

    // -----------------------------------------------------------------
    // maybePerformAs()
    // -----------------------------------------------------------------

    public function testMaybePerformAsWithGrantedPermissionPerformsAndReturnsOkResult(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->with('lang_translate_de', '0')->willReturn('0');
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs(6, ['language_key' => 'de', 'enabled' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame(
            ['language_key' => 'de', 'enabled' => true, 'changed' => true],
            $result->value()
        );
    }

    /**
     * Permission check regression: a denied "write" access must both yield
     * a Result\Error *and* leave the Setting collaborator completely
     * untouched (no read, no write) - a permission check that merely
     * rejected the outcome without preventing the read/write would not be a
     * real gate.
     */
    public function testMaybePerformAsWithDeniedPermissionReturnsErrorAndNeverTouchesSettings(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->with(6, 'write', $this->anything())
            ->willReturn(false);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $language = $this->createMock(\ILIAS\Language\Language::class);
        $language->method('txt')->with('msg_no_perm_write')->willReturn('no write permission');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings, language: $language)
            ->maybePerformAs(6, ['language_key' => 'de', 'enabled' => true]);

        $this->assertTrue($result->isError());
        $this->assertSame('no write permission', $result->error());
    }

    /**
     * A Throwable raised from within perform() (e.g. the Setting
     * collaborator failing to write) must be turned into a Result\Error
     * rather than propagating - this is the whole point of maybePerformAs()
     * wrapping perform() in a try/catch.
     */
    public function testThrowableFromWithinPerformIsTurnedIntoAResultError(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->willReturn('0');
        $settings->method('set')->willThrowException(new \RuntimeException('database write failed'));

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs(6, ['language_key' => 'de', 'enabled' => true]);

        $this->assertTrue($result->isError());
        $error = $result->error();
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('database write failed', $error->getMessage());
    }

    /**
     * normalizeParameters() validates before isAllowedToPerform() is even
     * reached - a missing raw_parameters key must surface as a
     * Result\Error, not an uncaught exception, and must never touch the
     * rbac system (validation happens first, exactly like the sibling
     * Activities in this component).
     */
    public function testMaybePerformAsWithMissingRawParametersKeysIsAResultErrorAndNeverChecksPermission(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $result = $this->createActivity(rbac_system: $rbac)->maybePerformAs(6, []);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
    }

    public static function invalidRawParametersProvider(): array
    {
        return [
            'missing language_key' => [['enabled' => true]],
            'missing enabled' => [['language_key' => 'de']],
            'blank language_key' => [['language_key' => '   ', 'enabled' => true]],
            'non-string language_key' => [['language_key' => 42, 'enabled' => true]],
            'non-bool enabled: string "1"' => [['language_key' => 'de', 'enabled' => '1']],
            'non-bool enabled: int 1' => [['language_key' => 'de', 'enabled' => 1]],
            'non-bool enabled: null' => [['language_key' => 'de', 'enabled' => null]],
        ];
    }

    #[DataProvider('invalidRawParametersProvider')]
    public function testMaybePerformAsRejectsInvalidRawParametersAsResultErrorWithoutTouchingSettingsOrRbac(
        array $raw_parameters
    ): void {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs(6, $raw_parameters);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
    }

    /**
     * normalizeParameters()'s toNonEmptyString() must trim language_key
     * before it reaches perform() - exercised end-to-end through
     * maybePerformAs() so a caller submitting e.g. a copy-pasted language
     * key with surrounding whitespace still resolves to the same setting
     * key as perform() itself would (see
     * testPerformTrimsLanguageKeyForBothTheSettingKeyAndTheReturnedValue).
     */
    public function testMaybePerformAsTrimsLanguageKeyBeforeBuildingTheSettingKey(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->with('lang_translate_de', '0')->willReturn('0');
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs(6, ['language_key' => '  de  ', 'enabled' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame('de', $result->value()['language_key']);
    }

    // -----------------------------------------------------------------
    // getInputDescription() / getOutputDescription() / getDescription()
    // -----------------------------------------------------------------

    public function testInputDescriptionBuildsAGroupWithARequiredLanguageKeyTextFieldAndAnEnabledCheckbox(): void
    {
        $text = $this->createMock(\ILIAS\UI\Component\Input\Field\Text::class);
        $text->expects($this->once())->method('withRequired')->with(true)->willReturnSelf();
        $text->expects($this->once())->method('withDedicatedName')->with('language_key')->willReturnSelf();

        $checkbox = $this->createMock(\ILIAS\UI\Component\Input\Field\Checkbox::class);
        $checkbox->expects($this->once())
            ->method('withDedicatedName')
            ->with('enabled')
            ->willReturnSelf();

        $field = $this->createMock(\ILIAS\UI\Component\Input\Field\Factory::class);
        $field->expects($this->once())
            ->method('text')
            ->with(
                'Language key',
                'Language key the page translation setting applies to, e.g. de, fr, it.'
            )
            ->willReturn($text);
        $field->expects($this->once())
            ->method('checkbox')
            ->with(
                'Enabled',
                'Whether page translation should be enabled for this language.'
            )
            ->willReturn($checkbox);

        $group = $this->createMock(\ILIAS\UI\Component\Input\Field\Group::class);
        $field->expects($this->once())
            ->method('group')
            ->with(['language_key' => $text, 'enabled' => $checkbox])
            ->willReturn($group);

        $input = $this->createMock(\ILIAS\UI\Component\Input\Factory::class);
        $input->method('field')->willReturn($field);

        $ui_factory = $this->createMock(UIFactory::class);
        $ui_factory->method('input')->willReturn($input);

        $activity = $this->createActivity(ui_factory: $ui_factory);

        $this->assertSame($group, $activity->getInputDescription());
    }

    /**
     * Uses a real DescriptionFactory (like AddLanguageEntryTest's own
     * output-description test) rather than a mocked one, so that
     * getFields()'s *actual* iteration order is asserted - a mocked
     * object()->with([...]) call would accept the three fields in any
     * order (PHPUnit's array comparison is order-independent), silently
     * missing a mutation that swapped e.g. 'enabled' and 'changed'.
     */
    public function testOutputDescriptionDescribesTheThreeDocumentedFieldsInOrder(): void
    {
        $string_group = $this->createMock(StringGroup::class);
        $string_group->method('markdown')->willReturn(
            $this->createMock(MarkdownFormattingToHTML::class)
        );
        $refinery = $this->createMock(RefineryFactory::class);
        $refinery->method('string')->willReturn($string_group);

        $activity = $this->createActivity(refinery: $refinery);

        $description = $activity->getOutputDescription(new DescriptionFactory());

        $field_names = [];
        foreach ($description->getFields() as $field) {
            $field_names[] = $field->getName();
        }

        $this->assertSame(['language_key', 'enabled', 'changed'], $field_names);
    }

    public function testGetDescriptionReturnsANonEmptyMarkdownDocument(): void
    {
        $description = $this->createActivity()->getDescription();

        $this->assertInstanceOf(\ILIAS\Data\Text\SimpleDocumentMarkdown::class, $description);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function createActivity(
        ?UIFactory $ui_factory = null,
        ?\ilRbacSystem $rbac_system = null,
        ?Setting $settings = null,
        ?\ILIAS\Language\Language $language = null,
        int $language_folder_ref_id = 0,
        ?RefineryFactory $refinery = null,
    ): SetLanguageTranslationEnabled {
        return new SetLanguageTranslationEnabled(
            $refinery ?? $this->createMock(RefineryFactory::class),
            $ui_factory ?? $this->createMock(UIFactory::class),
            $language ?? $this->createMock(\ILIAS\Language\Language::class),
            $rbac_system ?? $this->createMock(\ilRbacSystem::class),
            $settings ?? $this->createMock(Setting::class),
            $language_folder_ref_id
        );
    }
}
