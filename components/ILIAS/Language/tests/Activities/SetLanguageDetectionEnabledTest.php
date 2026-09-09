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
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Factory as UIFactory;
use ilLanguageBaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SetLanguageDetectionEnabled has no ilSetupLanguage collaborator and no
 * $lng_objects/$obj_language_factory closures (unlike UninstallLanguage/
 * RemoveLocalLanguageChanges) - instead it wraps a single
 * \ILIAS\Administration\Setting collaborator ("lang_detection") which is
 * a plain interface and can therefore be mocked directly, unlike
 * \ilObjLanguage on the sibling Activities.
 *
 * The most important regression this file guards is the *new* enforced
 * "write" RBAC check in isAllowedToPerform()/maybePerformAs(): before this
 * extraction, neither enableLanguageDetectionObject() nor
 * disableLanguageDetectionObject() performed any permission check of their
 * own - see this class's docblock for the full history. A test here must
 * prove that a denied permission both yields a Result\Error *and* leaves
 * the Setting collaborator untouched (no side effect on denial).
 */
class SetLanguageDetectionEnabledTest extends ilLanguageBaseTestCase
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

        $this->assertSame(SetLanguageDetectionEnabled::class, (string) $activity->getName());
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

        $this->assertTrue($activity->isAllowedToPerform(6, ['enabled' => true]));
    }

    public function testIsAllowedToPerformReturnsFalseWhenRbacDenies(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(false);

        $activity = $this->createActivity(rbac_system: $rbac);

        $this->assertFalse($activity->isAllowedToPerform(6, ['enabled' => true]));
    }

    /**
     * isAllowedToPerform() must be a pure permission check: it must never
     * touch the Setting collaborator (and therefore never write
     * "lang_detection") - unlike perform(), which is the only place
     * allowed to have side effects.
     */
    public function testIsAllowedToPerformNeverTouchesSettings(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity(rbac_system: $rbac, settings: $settings);

        $this->assertTrue($activity->isAllowedToPerform(6, ['enabled' => true]));
    }

    // -----------------------------------------------------------------
    // perform()
    // -----------------------------------------------------------------

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

        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(settings: $settings)->perform([]);
    }

    public function testPerformRejectsNonArrayParameters(): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(settings: $settings)->perform('not-an-array');
    }

    /**
     * Boundary/type-juggling regression: perform() must require a strict
     * bool for 'enabled' and must never silently cast a truthy/falsy
     * look-alike (string "1"/"0"/"true", int 1, etc.) - unlike PHP's own
     * loose comparison rules, which would happily accept any of these.
     */
    #[DataProvider('nonBooleanEnabledValuesProvider')]
    public function testPerformRejectsNonStrictBooleanEnabledValues(mixed $value): void
    {
        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $this->expectException(\InvalidArgumentException::class);

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

    // -----------------------------------------------------------------
    // maybePerformAs()
    // -----------------------------------------------------------------

    public function testMaybePerformAsWithGrantedPermissionPerformsAndReturnsOkResult(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->once())->method('set')->with('lang_detection', '1');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs(6, ['enabled' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame(['enabled' => true], $result->value());
    }

    /**
     * Core security regression test (see class docblock): before this
     * extraction, enableLanguageDetectionObject()/
     * disableLanguageDetectionObject() performed no RBAC check of their own
     * at all - a request forged directly against the corresponding ilCtrl
     * commands by a user with only "read" access on the language folder
     * would still have flipped "lang_detection". isAllowedToPerform() now
     * enforces a "write" check, and this must actually cause
     * maybePerformAs() to reject the request (Result\Error) *and* leave the
     * Setting collaborator completely untouched - a permission check that
     * merely rejected the outcome without preventing the side effect would
     * not close the gap at all.
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

        $language = $this->createMock(\ILIAS\Language\Language::class);
        $language->method('txt')->with('msg_no_perm_write')->willReturn('no write permission');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings, language: $language)
            ->maybePerformAs(6, ['enabled' => true]);

        $this->assertTrue($result->isError());
    }

    /**
     * A Throwable raised from within perform() (or normalizeParameters())
     * must be turned into a Result\Error rather than propagating - this is
     * the whole point of maybePerformAs() wrapping perform() in a
     * try/catch.
     */
    public function testMaybePerformAsTurnsAThrowableFromNormalizeParametersIntoAResultError(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs(6, ['enabled' => 'not-a-bool']);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
    }

    /**
     * normalizeParameters() validates 'enabled' before isAllowedToPerform()
     * is even reached - a missing parameter must likewise surface as a
     * Result\Error, not an uncaught exception, and must not touch the rbac
     * system at all (validation happens first, exactly like the sibling
     * Activities in this component).
     */
    public function testMaybePerformAsWithMissingEnabledKeyIsAResultErrorAndNeverChecksPermission(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $result = $this->createActivity(rbac_system: $rbac)->maybePerformAs(6, []);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
    }

    /**
     * Grenzfall: non-bool 'enabled' values normalized via
     * normalizeParameters() must be rejected the same way as in perform()
     * itself (both call the same toBool()) - covered here end-to-end
     * through maybePerformAs() for the values most likely to appear from a
     * real caller (a webservice/JSON request sending "true"/1/null instead
     * of a strict boolean).
     */
    #[DataProvider('nonBooleanEnabledValuesProvider')]
    public function testMaybePerformAsRejectsNonStrictBooleanEnabledValuesWithoutTouchingSettings(mixed $value): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $settings = $this->createMock(Setting::class);
        $settings->expects($this->never())->method('set');

        $result = $this->createActivity(rbac_system: $rbac, settings: $settings)
            ->maybePerformAs(6, ['enabled' => $value]);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
    }

    // -----------------------------------------------------------------
    // getInputDescription() / getOutputDescription()
    // -----------------------------------------------------------------

    public function testInputDescriptionBuildsAGroupWithASingleEnabledCheckboxField(): void
    {
        $checkbox = $this->createMock(\ILIAS\UI\Component\Input\Field\Checkbox::class);
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

        $group = $this->createMock(\ILIAS\UI\Component\Input\Field\Group::class);
        $field->expects($this->once())
            ->method('group')
            ->with(['enabled' => $checkbox])
            ->willReturn($group);

        $input = $this->createMock(\ILIAS\UI\Component\Input\Factory::class);
        $input->method('field')->willReturn($field);

        $ui_factory = $this->createMock(UIFactory::class);
        $ui_factory->method('input')->willReturn($input);

        $activity = $this->createActivity(ui_factory: $ui_factory);

        $this->assertSame($group, $activity->getInputDescription());
    }

    public function testOutputDescriptionDescribesASingleBooleanEnabledField(): void
    {
        $bool_description = $this->createMock(\ILIAS\Data\Description\Description::class);
        $object_description = $this->createMock(\ILIAS\Data\Description\Description::class);

        $f = $this->createMock(\ILIAS\Data\Description\Factory::class);
        $f->expects($this->once())->method('bool')->willReturn($bool_description);
        $f->expects($this->once())
            ->method('object')
            ->with($this->anything(), ['enabled' => $bool_description])
            ->willReturn($object_description);

        $this->assertSame($object_description, $this->createActivity()->getOutputDescription($f));
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function createActivity(
        ?UIFactory $ui_factory = null,
        ?\ilRbacSystem $rbac_system = null,
        ?Setting $settings = null,
        ?\ILIAS\Language\Language $language = null,
        int $language_folder_ref_id = 0
    ): SetLanguageDetectionEnabled {
        return new SetLanguageDetectionEnabled(
            $this->createMock(RefineryFactory::class),
            $ui_factory ?? $this->createMock(UIFactory::class),
            $language ?? $this->createMock(\ILIAS\Language\Language::class),
            $rbac_system ?? $this->createMock(\ilRbacSystem::class),
            $settings ?? $this->createMock(Setting::class),
            $language_folder_ref_id
        );
    }
}
