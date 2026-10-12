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
use ILIAS\Language\Activities\SetLanguageDetectionEnabled;
use ILIAS\Language\Language;
use ILIAS\UI\Component\Input\Field\Checkbox;
use ILIAS\UI\Component\Input\Field\Group;
use ILIAS\Data\Description\Description;
use ILIAS\Administration\Setting;
use ILIAS\Refinery\Factory as RefineryFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SetLanguageDetectionEnabled wraps a single \ILIAS\Administration\Setting ("lang_detection"), which is a plain
 * interface and can be mocked directly, unlike \ilObjLanguage on the sibling Activities. A denied permission must
 * yield a Result\Error and leave the Setting untouched.
 */
class SetLanguageDetectionEnabledTest extends ActivityContractTestCase
{
    protected function createDefaultActivity(): SetLanguageDetectionEnabled
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

        $this->assertTrue($activity->isAllowedToPerform(6, ['enabled' => true]));
    }

    public function testIsAllowedToPerformReturnsFalseWhenRbacDenies(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(false);

        $activity = $this->createActivity(rbac_system: $rbac);

        $this->assertFalse($activity->isAllowedToPerform(6, ['enabled' => true]));
    }

    /**
     * isAllowedToPerform() must be a pure permission check: it must never touch the Setting collaborator.
     */
    public function testIsAllowedToPerformNeverTouchesSettings(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity(rbac_system: $rbac, settings: $settings);

        $this->assertTrue($activity->isAllowedToPerform(6, ['enabled' => true]));
    }

    public function testPerformWithEnabledTrueWritesOneIntoLangDetectionSettingAndReturnsEnabledTrue(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('lang_detection', '1');

        $result = $this->createActivity(settings: $settings)->perform(['enabled' => true]);

        $this->assertSame(['enabled' => true], $result);
    }

    public function testPerformWithEnabledFalseWritesZeroIntoLangDetectionSettingAndReturnsEnabledFalse(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('lang_detection', '0');

        $result = $this->createActivity(settings: $settings)->perform(['enabled' => false]);

        $this->assertSame(['enabled' => false], $result);
    }

    public function testPerformRejectsMissingParametersArray(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $this->expectException(InvalidInputException::class);

        $this->createActivity(settings: $settings)->perform([]);
    }

    public function testPerformRejectsNonArrayParameters(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $this->expectException(InvalidInputException::class);

        $this->createActivity(settings: $settings)->perform('not-an-array');
    }

    /**
     * perform() requires a strict bool for 'enabled' and must not cast look-alikes ("1", "0", "true", 1).
     */
    #[DataProvider('nonBooleanEnabledValuesProvider')]
    public function testPerformRejectsNonStrictBooleanEnabledValues(mixed $value): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $this->expectException(InvalidInputException::class);

        $this->createActivity(settings: $settings)->perform(['enabled' => $value]);
    }

    public static function nonBooleanEnabledValuesProvider(): array
    {
        return [
            'string "1"' => ['1'],
            'string "true"' => ['true'],
            'string "0"' => ['0'],
            'string "false"' => ['false'],
            'string ""' => [''],
            'int 1' => [1],
            'int 0' => [0],
            'float 1.0' => [1.0],
            'null' => [null],
            'array' => [[true]],
        ];
    }

    public function testMaybePerformAsWithGrantedPermissionPerformsAndReturnsOkResult(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())->method('set')->with('lang_detection', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['enabled' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame(['enabled' => true], $result->value());
    }

    /**
     * A "read"-only user forging the ilCtrl command must be rejected by maybePerformAs() without flipping
     * "lang_detection": the check must prevent the side effect, not merely reject the outcome.
     */
    public function testMaybePerformAsWithDeniedPermissionReturnsErrorAndNeverWritesTheSetting(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->with(6, 'write', $this->anything())
            ->willReturn(false);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $language = $this->createStub(Language::class);
        $language->method('txt')->willReturnMap([
            ['msg_no_perm_write', 'no write permission'],
        ]);

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings, language: $language)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['enabled' => true]);

        $this->assertTrue($result->isError());
    }

    /**
     * A Throwable from perform() or normalizeParameters() must be turned into a Result\Error.
     */
    public function testMaybePerformAsTurnsAThrowableFromNormalizeParametersIntoAResultError(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['enabled' => 'not-a-bool']);

        $this->assertTrue($result->isError());
        // outside the whitelist of normalizeCheckboxRawValue(): grind() turns it into an InvalidInputException
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
    }

    /**
     * 'enabled' is not required: a missing key defaults to false, like an unchecked and therefore unsubmitted HTML checkbox.
     */
    public function testMaybePerformAsWithMissingEnabledKeyDefaultsToFalseAndStillPerformsTheWrite(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())->method('set')->with('lang_detection', '0');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, []);

        $this->assertFalse($result->isError());
        $this->assertSame(['enabled' => false], $result->value());
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
    public function testMaybePerformAsToleratesCommonPrimitiveTruthyRepresentationsAndWritesEnabledTrue(mixed $value): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())->method('set')->with('lang_detection', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['enabled' => $value]);

        $this->assertFalse($result->isError());
        $this->assertSame(['enabled' => true], $result->value());
    }

    /**
     * Same tolerance for the common representations of "false".
     */
    #[DataProvider('tolerantlyAcceptedFalsyEnabledValuesProvider')]
    public function testMaybePerformAsToleratesCommonPrimitiveFalsyRepresentationsAndWritesEnabledFalse(mixed $value): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())->method('set')->with('lang_detection', '0');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['enabled' => $value]);

        $this->assertFalse($result->isError());
        $this->assertSame(['enabled' => false], $result->value());
    }

    public static function rejectedEnabledValuesProvider(): array
    {
        return [
            'float 1.0' => [1.0],
            'array' => [[true]],
        ];
    }

    /**
     * Values outside the whitelist of GrindsFormInput::normalizeCheckboxRawValue() are still rejected.
     */
    #[DataProvider('rejectedEnabledValuesProvider')]
    public function testMaybePerformAsRejectsValuesGrindCannotInterpretAsACheckboxWithoutTouchingSettings(mixed $value): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['enabled' => $value]);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
    }

    public function testInputDescriptionBuildsAGroupWithASingleEnabledCheckboxField(): void
    {
        $checkbox = $this->createMock(Checkbox::class);
        $checkbox->expects($this->once())
            ->method('withDedicatedName')
            ->with('enabled')
            ->willReturnSelf();

        $field = $this->createMock(\ILIAS\UI\Component\Input\Field\Factory::class);
        $field->expects($this->once())
            ->method('checkbox')
            ->with(
                'Enabled',
                'Whether automatic language detection from the browser should be enabled.'
            )
            ->willReturn($checkbox);

        $group = $this->createStub(Group::class);
        $field->expects($this->once())
            ->method('group')
            ->with(['enabled' => $checkbox])
            ->willReturn($group);

        $activity = $this->createActivity();

        $this->assertSame($group, $activity->getInputDescription($field));
    }

    public function testOutputDescriptionDescribesASingleBooleanEnabledField(): void
    {
        $bool_description = $this->createStub(Description::class);
        $object_description = $this->createStub(Description::class);

        $f = $this->createMock(\ILIAS\Data\Description\Factory::class);
        $f->expects($this->once())->method('bool')->willReturn($bool_description);
        $f->expects($this->once())
            ->method('object')
            ->with($this->anything(), ['enabled' => $bool_description])
            ->willReturn($object_description);

        $this->assertSame($object_description, $this->createActivity()->getOutputDescription($f));
    }

    private function createActivity(
        ?\ilRbacSystem $rbac_system = null,
        ?Setting $settings = null,
        ?Language $language = null,
        int $language_folder_ref_id = 0
    ): SetLanguageDetectionEnabled {
        return new SetLanguageDetectionEnabled(
            $this->createStub(RefineryFactory::class),
            $language ?? $this->createStub(Language::class),
            $this->rbacOf($rbac_system ?? $this->createStub(\ilRbacSystem::class)),
            $settings ?? $this->createStub(Setting::class),
            $this->languageFolder($language_folder_ref_id)
        );
    }
}
