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

use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Factory as UIFactory;
use ilLanguageBaseTestCase;

/**
 * UninstallLanguage has no ilSetupLanguage collaborator (unlike
 * InstallLanguage/UpdateLanguage) - instead it resolves language keys to
 * objects via two closures, $lng_objects and $obj_language_factory. The
 * object returned by $obj_language_factory must behave like \ilObjLanguage
 * (isSystemLanguage(), isUserLanguage(), isInstalled(), uninstall()), but
 * \ilObjLanguage itself cannot be constructed or meaningfully mocked in a
 * unit test (its constructor needs the global $DIC) - so tests use a small
 * fake object instead, injected through $obj_language_factory.
 */
class UninstallLanguageTest extends ilLanguageBaseTestCase
{
    /**
     * @param array<string, array{system?: bool, user?: bool, installed?: bool, uninstall_return?: string}> $language_objects
     *        Keyed by language key; each entry becomes a fake object with
     *        the given isSystemLanguage()/isUserLanguage()/isInstalled()
     *        answers (default false/false/true) and uninstall() return
     *        value (default 'uninstalled') reachable from the
     *        corresponding obj_id (assigned in iteration order, starting at 1).
     * @return array{0: array<int, FakeLanguageObject>, 1: \Closure, 2: \Closure}
     *         [obj id => fake object, lng_objects closure, obj_language_factory closure]
     */
    private function buildFakeLanguageWorld(array $language_objects): array
    {
        $lng_objects_list = [];
        $fakes_by_obj_id = [];
        $obj_id = 1;

        foreach ($language_objects as $language_key => $flags) {
            $lng_objects_list[] = ['obj_id' => $obj_id, 'title' => $language_key];
            $fakes_by_obj_id[$obj_id] = new FakeLanguageObject(
                is_system_language: $flags['system'] ?? false,
                is_user_language: $flags['user'] ?? false,
                is_installed: $flags['installed'] ?? true,
                uninstall_return_value: $flags['uninstall_return'] ?? 'uninstalled',
            );
            $obj_id++;
        }

        $lng_objects = static fn(): array => $lng_objects_list;
        $obj_language_factory = static fn(int $id): FakeLanguageObject => $fakes_by_obj_id[$id];

        return [$fakes_by_obj_id, $lng_objects, $obj_language_factory];
    }

    public function testInstalledOrdinaryLanguageIsUninstalled(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame(['de'], $result['uninstalled_language_keys']);
        $this->assertSame([], $result['system_language_keys']);
        $this->assertSame([], $result['user_language_keys']);
        $this->assertSame([], $result['not_installed_language_keys']);
        $this->assertSame(1, $fakes[1]->uninstallCallCount());
    }

    public function testSystemLanguageIsNeverUninstalled(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['system' => true],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['uninstalled_language_keys']);
        $this->assertSame(['de'], $result['system_language_keys']);
        $this->assertSame([], $result['user_language_keys']);
        $this->assertSame([], $result['not_installed_language_keys']);
        $this->assertSame(0, $fakes[1]->uninstallCallCount());
    }

    public function testLanguageCurrentlyInUseIsNeverUninstalled(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['user' => true],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['uninstalled_language_keys']);
        $this->assertSame([], $result['system_language_keys']);
        $this->assertSame(['de'], $result['user_language_keys']);
        $this->assertSame([], $result['not_installed_language_keys']);
        $this->assertSame(0, $fakes[1]->uninstallCallCount());
    }

    /**
     * isSystemLanguage() takes priority: a language that is somehow flagged
     * as both the system language and the language currently in use must be
     * reported as the system language, not as "in use" - the two branches
     * are checked in that order in perform().
     */
    public function testSystemLanguageTakesPriorityOverUserLanguage(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['system' => true, 'user' => true],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame(['de'], $result['system_language_keys']);
        $this->assertSame([], $result['user_language_keys']);
    }

    public function testExistingButNotInstalledLanguageObjectIsReportedAsNotInstalled(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['installed' => false],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['uninstalled_language_keys']);
        $this->assertSame([], $result['system_language_keys']);
        $this->assertSame([], $result['user_language_keys']);
        $this->assertSame(['de'], $result['not_installed_language_keys']);
        $this->assertSame(0, $fakes[1]->uninstallCallCount());
    }

    /**
     * Regression test: uninstall() itself is the authority on whether the
     * uninstallation actually happened, not merely the three outer guards
     * (isSystemLanguage()/isUserLanguage()/isInstalled()). A language that
     * passes all three guards (so perform() does call uninstall()) but
     * whose uninstall() nonetheless returns '' (e.g. because a future,
     * additional internal guard inside \ilObjLanguage::uninstall() itself
     * rejects it) must be reported as not_installed_language_keys, never
     * as uninstalled_language_keys - anything else would misreport a
     * rejected uninstallation as a success.
     */
    public function testLanguageWhereUninstallItselfRejectsIsReportedAsNotInstalled(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['uninstall_return' => ''],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['uninstalled_language_keys']);
        $this->assertSame([], $result['system_language_keys']);
        $this->assertSame([], $result['user_language_keys']);
        $this->assertSame(['de'], $result['not_installed_language_keys']);
        // uninstall() must still have been attempted - unlike the guarded
        // cases (system/user/not-installed), where it is never called.
        $this->assertSame(1, $fakes[1]->uninstallCallCount());
    }

    /**
     * A language key with no corresponding "lng" object at all must be
     * reported as not installed - and, crucially, $obj_language_factory
     * must never even be invoked for it (there is no obj_id to construct it
     * from in the first place).
     */
    public function testUnknownLanguageKeyIsReportedAsNotInstalledWithoutTouchingTheObjectFactory(): void
    {
        $lng_objects = static fn(): array => [];
        $factory_calls = [];
        $obj_language_factory = static function (int $id) use (&$factory_calls): FakeLanguageObject {
            $factory_calls[] = $id;
            throw new \LogicException('must never be called for an unknown language key');
        };

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'xx',
        ]);

        $this->assertSame([], $result['uninstalled_language_keys']);
        $this->assertSame([], $result['system_language_keys']);
        $this->assertSame([], $result['user_language_keys']);
        $this->assertSame(['xx'], $result['not_installed_language_keys']);
        $this->assertSame([], $factory_calls);
    }

    /**
     * A single request can carry languages in every possible state at once;
     * each must land in its own bucket, and only the genuinely uninstalled
     * one may ever call uninstall().
     */
    public function testMixedBulkRequestPartitionsEachKeyIntoTheCorrectBucket(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
            'en' => ['system' => true],
            'fr' => ['user' => true],
            'it' => ['installed' => false],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            // 'xx' has no backing object at all.
            'language_keys' => ['de', 'en', 'fr', 'it', 'xx'],
        ]);

        $this->assertSame(['de'], $result['uninstalled_language_keys']);
        $this->assertSame(['en'], $result['system_language_keys']);
        $this->assertSame(['fr'], $result['user_language_keys']);
        $this->assertSame(['it', 'xx'], $result['not_installed_language_keys']);

        $this->assertSame(1, $fakes[1]->uninstallCallCount(), 'de must be uninstalled');
        $this->assertSame(0, $fakes[2]->uninstallCallCount(), 'system language must never be uninstalled');
        $this->assertSame(0, $fakes[3]->uninstallCallCount(), 'in-use language must never be uninstalled');
        $this->assertSame(0, $fakes[4]->uninstallCallCount(), 'not-installed language must never be uninstalled');
    }

    public function testDuplicateLanguageKeysAcrossStringAndArrayAreDeduplicated(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => [' de, de ', 'de'],
        ]);

        $this->assertSame(['de'], $result['uninstalled_language_keys']);
        $this->assertSame(1, $fakes[1]->uninstallCallCount());
    }

    public function testMissingLanguageKeysParameterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(static fn(): array => [], static fn(int $id) => null)->perform([]);
    }

    public function testEmptyLanguageKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(static fn(): array => [], static fn(int $id) => null)->perform([
            'language_keys' => ' , ',
        ]);
    }

    public function testInvalidLanguageKeysTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(static fn(): array => [], static fn(int $id) => null)->perform([
            'language_keys' => ['de', ['fr']],
        ]);
    }

    public function testInputDescriptionUsesOnlyTheLanguageKeysFieldWithNoModeField(): void
    {
        $text = $this->createMock(\ILIAS\UI\Component\Input\Field\Text::class);
        $text->expects($this->once())->method('withRequired')->with(true)->willReturnSelf();
        $text->expects($this->once())
            ->method('withDedicatedName')
            ->with('language_keys')
            ->willReturnSelf();

        $field = $this->createMock(\ILIAS\UI\Component\Input\Field\Factory::class);
        $field->expects($this->once())->method('text')->with(
            'Language keys',
            'Comma-separated list of language keys, e.g. de, fr, it.'
        )->willReturn($text);
        // UninstallLanguage has no mode parameter - the select field must
        // never be built.
        $field->expects($this->never())->method('select');

        $group = $this->createMock(\ILIAS\UI\Component\Input\Field\Group::class);
        $field->expects($this->once())
            ->method('group')
            ->with(['language_keys' => $text])
            ->willReturn($group);

        $input = $this->createMock(\ILIAS\UI\Component\Input\Factory::class);
        $input->method('field')->willReturn($field);

        $ui_factory = $this->createMock(UIFactory::class);
        $ui_factory->method('input')->willReturn($input);

        $activity = $this->createActivity(
            static fn(): array => [],
            static fn(int $id) => null,
            $ui_factory
        );

        $this->assertSame($group, $activity->getInputDescription());
    }

    public function testPermissionDeniedBeforePerformNeverCallsObjectFactoryOrUninstall(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->with(6, 'write', $this->anything())
            ->willReturn(false);

        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
        ]);
        $language = $this->createMock(\ILIAS\Language\Language::class);
        $language->method('txt')->with('msg_no_perm_write')->willReturn('no write permission');

        $result = $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            null,
            $rbac,
            $language
        )->maybePerformAs(6, ['language_keys' => 'de']);

        $this->assertTrue($result->isError());
        $this->assertSame(0, $fakes[1]->uninstallCallCount());
    }

    public function testPermissionGrantedPerformsAndReturnsOkResult(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
        ]);

        $result = $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            null,
            $rbac
        )->maybePerformAs(6, ['language_keys' => 'de']);

        $this->assertFalse($result->isError());
        $this->assertSame(['de'], $result->value()['uninstalled_language_keys']);
        $this->assertSame(1, $fakes[1]->uninstallCallCount());
    }

    private function createActivity(
        \Closure $lng_objects,
        \Closure $obj_language_factory,
        ?UIFactory $ui_factory = null,
        ?\ilRbacSystem $rbac = null,
        ?\ILIAS\Language\Language $language = null
    ): UninstallLanguage {
        return new UninstallLanguage(
            $this->createMock(RefineryFactory::class),
            $ui_factory ?? $this->createMock(UIFactory::class),
            $language ?? $this->createMock(\ILIAS\Language\Language::class),
            $rbac ?? $this->createMock(\ilRbacSystem::class),
            0,
            $lng_objects,
            $obj_language_factory
        );
    }
}

/**
 * Minimal stand-in for \ilObjLanguage: that class cannot be constructed or
 * meaningfully mocked in a unit test (its constructor requires the global
 * $DIC), so tests substitute this fake via UninstallLanguage's
 * $obj_language_factory closure instead. Counts uninstall() invocations so
 * tests can assert it was never called for a bucket other than
 * "uninstalled".
 */
final class FakeLanguageObject
{
    private int $uninstall_calls = 0;

    /**
     * @param string $uninstall_return_value What uninstall() returns - a
     *        non-empty string mimics a real, successful uninstall; ''
     *        mimics \ilObjLanguage::uninstall() internally rejecting the
     *        call despite the three outer guards (isSystemLanguage()/
     *        isUserLanguage()/isInstalled()) having let it through - e.g. a
     *        future additional guard added inside uninstall() itself.
     */
    public function __construct(
        private readonly bool $is_system_language,
        private readonly bool $is_user_language,
        private readonly bool $is_installed,
        private readonly string $uninstall_return_value = 'uninstalled',
    ) {
    }

    public function isSystemLanguage(): bool
    {
        return $this->is_system_language;
    }

    public function isUserLanguage(): bool
    {
        return $this->is_user_language;
    }

    public function isInstalled(): bool
    {
        return $this->is_installed;
    }

    public function uninstall(): string
    {
        $this->uninstall_calls++;

        return $this->uninstall_return_value;
    }

    public function uninstallCallCount(): int
    {
        return $this->uninstall_calls;
    }
}
