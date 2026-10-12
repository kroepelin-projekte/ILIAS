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

namespace ILIAS\Language\Tests\Activities;

use ILIAS\Language\Tests\Activities\ActivityContractTestCase;
use ILIAS\Language\Activities\InvalidInputException;
use ILIAS\Language\Activities\SafeToDisplayActivityError;
use ILIAS\Language\Activities\SetLanguageTranslationEnabled;
use ILIAS\Language\Language;
use ILIAS\UI\Component\Input\Field\Text;
use ILIAS\UI\Component\Input\Field\Checkbox;
use ILIAS\Administration\Setting;
use ILIAS\Data\Description\Factory as DescriptionFactory;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\Refinery\String\Group as StringGroup;
use ILIAS\Refinery\String\MarkdownFormattingToHTML;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Backed by a single mockable \ILIAS\Administration\Setting. Unlike SetLanguageDetectionEnabled it is parameterised by
 * a language_key ("lang_translate_<key>"), and perform() itself validates $parameters and writes only if the value
 * changed, comparing against the stored one with a falsy (bool) cast like ilObjLanguageAccess::_checkTranslate().
 * A denied "write" permission must prevent both the outcome and the Settings access.
 */
class SetLanguageTranslationEnabledTest extends ActivityContractTestCase
{
    protected function createDefaultActivity(): SetLanguageTranslationEnabled
    {
        return $this->createActivity();
    }

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
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(false);

        $activity = $this->createActivity(rbac_system: $rbac);

        $this->assertFalse($activity->isAllowedToPerform(6, ['language_key' => 'de', 'enabled' => true]));
    }

    /**
     * isAllowedToPerform() must be a pure permission check: it must never touch the Setting collaborator.
     */
    public function testIsAllowedToPerformNeverTouchesSettings(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity(rbac_system: $rbac, settings: $settings);

        $this->assertTrue($activity->isAllowedToPerform(6, ['language_key' => 'de', 'enabled' => true]));
    }

    /**
     * perform() reads the current value of "lang_translate_<key>" with a falsy check, like
     * ilObjLanguageAccess::_checkTranslate(), and writes only when the requested value differs.
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
        $settings->method('get')->willReturnMap([
            ['lang_translate_de', '0', $stored_value],
        ]);

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
     * get() must receive the key "lang_translate_" . language_key and the default '0'.
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
     * language_key is trimmed for the setting key and the returned value; perform() re-implements its own trim().
     */
    public function testPerformTrimsLanguageKeyForBothTheSettingKeyAndTheReturnedValue(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->method('get')->willReturnMap([
            ['lang_translate_de', '0', '0'],
        ]);
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '1');

        $result = $this->createActivity(settings: $settings)
            ->perform(['language_key' => '  de  ', 'enabled' => true]);

        $this->assertSame('de', $result['language_key']);
    }

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

        $this->expectException(InvalidInputException::class);

        $this->createActivity(settings: $settings)->perform($parameters);
    }

    public function testMaybePerformAsWithGrantedPermissionPerformsAndReturnsOkResult(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->willReturnMap([
            ['lang_translate_de', '0', '0'],
        ]);
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => 'de', 'enabled' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame(
            ['language_key' => 'de', 'enabled' => true, 'changed' => true],
            $result->value()
        );
    }

    /**
     * A denied "write" access must yield a Result\Error and leave the Setting untouched (no read, no write).
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

        $language = $this->createStub(Language::class);
        $language->method('txt')->willReturnMap([
            ['msg_no_perm_write', 'no write permission'],
        ]);

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings, language: $language)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => 'de', 'enabled' => true]);

        $this->assertTrue($result->isError());
        $this->assertSame('no write permission', $result->error());
    }

    /**
     * A Throwable from within perform() (e.g. the Setting failing to write) must become a Result\Error.
     */
    public function testThrowableFromWithinPerformIsTurnedIntoAResultError(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createStub(Setting::class);
        $settings->method('get')->willReturn('0');
        $settings->method('set')->willThrowException(new \RuntimeException('database write failed'));

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => 'de', 'enabled' => true]);

        $this->assertTrue($result->isError());
        $error = $result->error();
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('database write failed', $error->getMessage());
    }

    /**
     * A missing 'language_key' fails the required Text field in grind() itself, before
     * normalizeParameters()/isAllowedToPerform(): the result carries a field-attributed InvalidInputException.
     */
    public function testMaybePerformAsWithMissingRawParametersKeysIsAResultErrorAndNeverChecksPermission(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $result = $this->createActivity(rbac_system: $rbac)->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, []);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertNotSame('', $result->error()->getMessage());
    }

    public static function invalidRawParametersRejectedByGrindWithStringErrorProvider(): array
    {
        return [
            'missing language_key' => [['enabled' => true]],
        ];
    }

    #[DataProvider('invalidRawParametersRejectedByGrindWithStringErrorProvider')]
    public function testMaybePerformAsRejectsRawParametersAtGrindLevelWithStringErrorWithoutTouchingSettingsOrRbac(
        array $raw_parameters
    ): void {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, $raw_parameters);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertNotSame('', $result->error()->getMessage());
    }

    public static function invalidRawParametersProvider(): array
    {
        return [
            // a non-string 'language_key' fails the Text field's isClientSideValueOk() inside grind()
            'non-string language_key' => [['language_key' => 42, 'enabled' => true]],
            // outside the whitelist of GrindsFormInput::normalizeCheckboxRawValue()
            'non-bool enabled: array' => [['language_key' => 'de', 'enabled' => [true]]],
            'non-bool enabled: float 1.0' => [['language_key' => 'de', 'enabled' => 1.0]],
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
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, $raw_parameters);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
    }

    /**
     * 'enabled' is not required: a missing key defaults to false, like an unchecked HTML checkbox.
     */
    public function testMaybePerformAsWithMissingEnabledKeyDefaultsToFalseAndStillPerformsTheWrite(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->willReturnMap([
            ['lang_translate_de', '0', '1'],
        ]);
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '0');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => 'de']);

        $this->assertFalse($result->isError());
        $this->assertSame(
            ['language_key' => 'de', 'enabled' => false, 'changed' => true],
            $result->value()
        );
    }

    /**
     * A whitespace-only language_key passes the Text field's required-check (min length 1 before trimming), so the
     * request does reach isAllowedToPerform().
     */
    public function testMaybePerformAsRejectsWhitespaceOnlyLanguageKeyAfterThePermissionCheck(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => '   ', 'enabled' => true]);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
    }

    public static function tolerantlyAcceptedTruthyEnabledValuesProvider(): array
    {
        return [
            'string "1"' => ['1'],
            'string "true"' => ['true'],
            'int 1' => [1],
        ];
    }

    public static function tolerantlyAcceptedFalsyEnabledValuesProvider(): array
    {
        return [
            'string "0"' => ['0'],
            'string "false"' => ['false'],
            'string ""' => [''],
            'int 0' => [0],
            'null' => [null],
        ];
    }

    /**
     * Grinding tolerance (GrindsFormInput::normalizeCheckboxRawValue()): unlike perform(), maybePerformAs() accepts
     * the common primitive representations of "true".
     */
    #[DataProvider('tolerantlyAcceptedTruthyEnabledValuesProvider')]
    public function testMaybePerformAsToleratesCommonPrimitiveTruthyEnabledRepresentations(mixed $value): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->willReturnMap([
            ['lang_translate_de', '0', '0'],
        ]);
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => 'de', 'enabled' => $value]);

        $this->assertFalse($result->isError());
        $this->assertSame(
            ['language_key' => 'de', 'enabled' => true, 'changed' => true],
            $result->value()
        );
    }

    /**
     * Same tolerance for the common representations of "false".
     */
    #[DataProvider('tolerantlyAcceptedFalsyEnabledValuesProvider')]
    public function testMaybePerformAsToleratesCommonPrimitiveFalsyEnabledRepresentations(mixed $value): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->willReturnMap([
            ['lang_translate_de', '0', '1'],
        ]);
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '0');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => 'de', 'enabled' => $value]);

        $this->assertFalse($result->isError());
        $this->assertSame(
            ['language_key' => 'de', 'enabled' => false, 'changed' => true],
            $result->value()
        );
    }

    /**
     * An unknown/not installed language_key is rejected by perform() itself, also for direct callers.
     */
    public function testPerformRejectsUnknownLanguageKeyWithoutTouchingSettings(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $this->expectException(InvalidInputException::class);

        $this->createActivity(settings: $settings)
            ->perform(['language_key' => 'xx', 'enabled' => true]);
    }

    /**
     * The "Unknown language key" message embeds the key unescaped: escaping is
     * RendersActivityErrors::activityErrorMessage()'s job.
     */
    public function testPerformRejectsUnknownLanguageKeyMessageContainsTheRawUnescapedKey(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $dangerous_key = '<script>alert(1)</script>&"quoted"';

        try {
            $this->createActivity(settings: $settings)
                ->perform(['language_key' => $dangerous_key, 'enabled' => true]);
            $this->fail('Expected an InvalidInputException to be thrown.');
        } catch (InvalidInputException $e) {
            $this->assertStringContainsString($dangerous_key, $e->getMessage());
            $this->assertStringNotContainsString('&lt;script&gt;', $e->getMessage());
            $this->assertStringNotContainsString('&quot;', $e->getMessage());
        }
    }

    /**
     * End-to-end: an unknown language_key passes grind() and the permission check, so perform() rejects it
     * without touching the Setting.
     */
    public function testMaybePerformAsRejectsUnknownLanguageKeyAfterPermissionCheckAndNeverTouchesSettings(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('get');
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => 'xx', 'enabled' => true]);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
    }

    /**
     * normalizeParameters() trims language_key before perform(), so a padded key resolves to the same setting key.
     */
    public function testMaybePerformAsTrimsLanguageKeyBeforeBuildingTheSettingKey(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->method('get')->willReturnMap([
            ['lang_translate_de', '0', '0'],
        ]);
        $settings->expects($this->once())->method('set')->with('lang_translate_de', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_key' => '  de  ', 'enabled' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame('de', $result->value()['language_key']);
    }

    public function testInputDescriptionBuildsAGroupWithARequiredLanguageKeyTextFieldAndAnEnabledCheckbox(): void
    {
        $text = $this->createMock(Text::class);
        $text->expects($this->once())->method('withRequired')->with(true)->willReturnSelf();
        $text->expects($this->once())->method('withDedicatedName')->with('language_key')->willReturnSelf();

        $checkbox = $this->createMock(Checkbox::class);
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

        $group = $this->createStub(\ILIAS\UI\Component\Input\Field\Group::class);
        $field->expects($this->once())
            ->method('group')
            ->with(['language_key' => $text, 'enabled' => $checkbox])
            ->willReturn($group);

        $activity = $this->createActivity();

        $this->assertSame($group, $activity->getInputDescription($field));
    }

    /**
     * A real DescriptionFactory (not a mock) so the actual field order of getFields() is asserted; a mocked
     * object()->with([...]) accepts any order.
     */
    public function testOutputDescriptionDescribesTheThreeDocumentedFieldsInOrder(): void
    {
        $string_group = $this->createStub(StringGroup::class);
        $string_group->method('markdown')->willReturn(
            $this->createStub(MarkdownFormattingToHTML::class)
        );
        $refinery = $this->createStub(RefineryFactory::class);
        $refinery->method('string')->willReturn($string_group);

        $activity = $this->createActivity(refinery: $refinery);

        $description = $activity->getOutputDescription(new DescriptionFactory());

        $field_names = [];
        foreach ($description->getFields() as $field) {
            $field_names[] = $field->getName();
        }

        $this->assertSame(['language_key', 'enabled', 'changed'], $field_names);
    }

    private function createActivity(
        ?\ilRbacSystem $rbac_system = null,
        ?Setting $settings = null,
        ?Language $language = null,
        int $language_folder_ref_id = 0,
        ?RefineryFactory $refinery = null,
        ?InstalledLanguageRepository $installed_language_repository = null,
    ): SetLanguageTranslationEnabled {
        return new SetLanguageTranslationEnabled(
            $refinery ?? $this->createStub(RefineryFactory::class),
            $language ?? $this->createStub(Language::class),
            $this->rbacOf($rbac_system ?? $this->createStub(\ilRbacSystem::class)),
            $settings ?? $this->createStub(Setting::class),
            installed_language_repository: $installed_language_repository
                ?? new FakeInstalledLanguageRepository(static fn(): array => ['de', 'fr']),
            language_folder: $this->languageFolder($language_folder_ref_id),
        );
    }
}
