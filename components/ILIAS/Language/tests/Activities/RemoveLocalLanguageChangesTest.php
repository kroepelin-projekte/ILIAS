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

use ILIAS\Component\Activities\ActivityType;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Factory as UIFactory;
use ilLanguageBaseTestCase;

/**
 * RemoveLocalLanguageChanges has no ilSetupLanguage collaborator (unlike
 * InstallLanguage/UpdateLanguage) - instead it resolves language keys to
 * objects via two closures, $lng_objects and $obj_language_factory. The
 * object returned by $obj_language_factory must behave like \ilObjLanguage
 * (isInstalled(), removeLocalChanges()), but \ilObjLanguage itself cannot be
 * constructed or meaningfully mocked in a unit test (its constructor needs
 * the global $DIC) - so tests use a small fake object instead, injected
 * through $obj_language_factory.
 */
class RemoveLocalLanguageChangesTest extends ilLanguageBaseTestCase
{
    /**
     * @param array<string, array{installed?: bool, remove_local_changes_return?: bool}> $language_objects
     *        Keyed by language key; each entry becomes a fake object with
     *        the given isInstalled() answer (default true) and
     *        removeLocalChanges() return value (default true) reachable
     *        from the corresponding obj_id (assigned in iteration order,
     *        starting at 1).
     * @return array{0: array<int, FakeRemoveLocalLanguageChangesObject>, 1: \Closure, 2: \Closure}
     *         [obj id => fake object, lng_objects closure, obj_language_factory closure]
     */
    private function buildFakeLanguageWorld(array $language_objects): array
    {
        $lng_objects_list = [];
        $fakes_by_obj_id = [];
        $obj_id = 1;

        foreach ($language_objects as $language_key => $flags) {
            $lng_objects_list[] = ['obj_id' => $obj_id, 'title' => $language_key];
            $fakes_by_obj_id[$obj_id] = new FakeRemoveLocalLanguageChangesObject(
                is_installed: $flags['installed'] ?? true,
                remove_local_changes_return_value: $flags['remove_local_changes_return'] ?? true,
            );
            $obj_id++;
        }

        $lng_objects = static fn(): array => $lng_objects_list;
        $obj_language_factory = static fn(int $id): FakeRemoveLocalLanguageChangesObject => $fakes_by_obj_id[$id];

        return [$fakes_by_obj_id, $lng_objects, $obj_language_factory];
    }

    public function testGetTypeIsCommand(): void
    {
        $activity = $this->createActivity(static fn(): array => [], static fn(int $id) => null);

        $this->assertSame(ActivityType::Command, $activity->getType());
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

        $activity = $this->createActivity(
            static fn(): array => [],
            static fn(int $id) => null,
            null,
            $rbac,
            null,
            42
        );

        $this->assertTrue($activity->isAllowedToPerform(6, ['language_keys' => 'de']));
    }

    public function testIsAllowedToPerformReturnsFalseWhenRbacDenies(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(false);

        $activity = $this->createActivity(
            static fn(): array => [],
            static fn(int $id) => null,
            null,
            $rbac
        );

        $this->assertFalse($activity->isAllowedToPerform(6, ['language_keys' => 'de']));
    }

    /**
     * isAllowedToPerform() must be a pure permission check: it must never
     * touch $lng_objects or $obj_language_factory (and therefore never call
     * removeLocalChanges()) - unlike perform(), which is the only place
     * allowed to have side effects.
     */
    public function testIsAllowedToPerformNeverTouchesLngObjectsOrObjectFactory(): void
    {
        $lng_objects = static function (): array {
            throw new \LogicException('lng_objects must never be called by isAllowedToPerform()');
        };
        $obj_language_factory = static function (int $id) {
            throw new \LogicException('obj_language_factory must never be called by isAllowedToPerform()');
        };

        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity($lng_objects, $obj_language_factory, null, $rbac);

        $this->assertTrue($activity->isAllowedToPerform(6, ['language_keys' => 'de']));
    }

    // -----------------------------------------------------------------
    // perform()
    // -----------------------------------------------------------------

    public function testInstalledLanguageWithValidFileHasItsLocalChangesRemoved(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame(['de'], $result['removed_local_changes_language_keys']);
        $this->assertSame([], $result['invalid_language_file_keys']);
        $this->assertSame([], $result['not_installed_language_keys']);
        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount());
    }

    public function testExistingButNotInstalledLanguageObjectIsReportedAsNotInstalledWithoutCallingRemoveLocalChanges(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['installed' => false],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['removed_local_changes_language_keys']);
        $this->assertSame([], $result['invalid_language_file_keys']);
        $this->assertSame(['de'], $result['not_installed_language_keys']);
        $this->assertSame(0, $fakes[1]->removeLocalChangesCallCount());
    }

    /**
     * Regression test: removeLocalChanges() itself is the authority on
     * whether the language file was valid, not merely the outer
     * isInstalled() guard. A language that passes isInstalled() (so
     * perform() does call removeLocalChanges()) but whose
     * removeLocalChanges() nonetheless returns false (invalid language
     * file) must be reported as invalid_language_file_keys, never as
     * removed_local_changes_language_keys or not_installed_language_keys.
     */
    public function testInstalledLanguageWhereRemoveLocalChangesFailsIsReportedAsInvalidLanguageFile(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['remove_local_changes_return' => false],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['removed_local_changes_language_keys']);
        $this->assertSame(['de'], $result['invalid_language_file_keys']);
        $this->assertSame([], $result['not_installed_language_keys']);
        // removeLocalChanges() must still have been attempted.
        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount());
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
        $obj_language_factory = static function (int $id) use (&$factory_calls) {
            $factory_calls[] = $id;
            throw new \LogicException('must never be called for an unknown language key');
        };

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => 'xx',
        ]);

        $this->assertSame([], $result['removed_local_changes_language_keys']);
        $this->assertSame([], $result['invalid_language_file_keys']);
        $this->assertSame(['xx'], $result['not_installed_language_keys']);
        $this->assertSame([], $factory_calls);
    }

    /**
     * A single request can carry languages in every possible state at once;
     * each must land in its own bucket, and removeLocalChanges() must only
     * ever be invoked for the two genuinely installed languages (regardless
     * of whether their file turns out valid or not) - never for the
     * not-installed or unknown ones.
     */
    public function testMixedBulkRequestPartitionsEachKeyIntoTheCorrectBucket(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
            'fr' => ['remove_local_changes_return' => false],
            'it' => ['installed' => false],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            // 'xx' has no backing object at all.
            'language_keys' => ['de', 'fr', 'it', 'xx'],
        ]);

        $this->assertSame(['de'], $result['removed_local_changes_language_keys']);
        $this->assertSame(['fr'], $result['invalid_language_file_keys']);
        $this->assertSame(['it', 'xx'], $result['not_installed_language_keys']);

        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount(), 'de must have its local changes removed');
        $this->assertSame(1, $fakes[2]->removeLocalChangesCallCount(), 'fr is installed, so must still be attempted');
        $this->assertSame(0, $fakes[3]->removeLocalChangesCallCount(), 'not-installed language must never be attempted');
    }

    public function testDuplicateLanguageKeysAcrossStringAndArrayAreDeduplicated(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => [' de, de ', 'de'],
        ]);

        $this->assertSame(['de'], $result['removed_local_changes_language_keys']);
        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount());
    }

    public function testCommaSeparatedStringIsNormalizedIntoMultipleLanguageKeys(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
            'fr' => [],
            'it' => [],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => ' de, fr ,it',
        ]);

        $this->assertSame(['de', 'fr', 'it'], $result['removed_local_changes_language_keys']);
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

    public function testNonArrayNonStringLanguageKeysValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(static fn(): array => [], static fn(int $id) => null)->perform([
            'language_keys' => 42,
        ]);
    }

    public function testNonArrayParametersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createActivity(static fn(): array => [], static fn(int $id) => null)->perform('not-an-array');
    }

    // -----------------------------------------------------------------
    // maybePerformAs()
    // -----------------------------------------------------------------

    /**
     * Regression test analogous to
     * UninstallLanguageTest::testPermissionDeniedBeforePerformNeverCallsObjectFactoryOrUninstall():
     * a denied permission must short-circuit before perform() ever runs -
     * $lng_objects/$obj_language_factory (and therefore removeLocalChanges())
     * must never be touched.
     */
    public function testPermissionDeniedBeforePerformNeverCallsObjectFactoryOrRemoveLocalChanges(): void
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
        $this->assertSame(0, $fakes[1]->removeLocalChangesCallCount());
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
        $this->assertSame(['de'], $result->value()['removed_local_changes_language_keys']);
        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount());
    }

    /**
     * A Throwable raised from within perform() (e.g. a validation failure
     * on the normalized parameters, or any other unexpected failure) must
     * be turned into a Result\Error rather than propagating - this is the
     * whole point of maybePerformAs() wrapping perform() in a try/catch.
     */
    public function testThrowableFromPerformIsTurnedIntoAResultError(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $lng_objects = static fn(): array => [];
        $obj_language_factory = static fn(int $id) => null;

        // An empty language_keys value normalizes to [] and throws inside
        // perform() (via toLanguageKeyList()) - reachable only after the
        // permission check has already passed, exercising the catch block.
        $result = $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            null,
            $rbac
        )->maybePerformAs(6, ['language_keys' => ' , ']);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
    }

    /**
     * normalizeParameters() itself validates language_keys before
     * isAllowedToPerform() is even reached - a missing parameter must
     * likewise surface as a Result\Error, not an uncaught exception, and
     * must not touch the rbac system at all (validation happens first).
     */
    public function testMissingLanguageKeysParameterViaMaybePerformAsIsAResultErrorAndNeverChecksPermission(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $result = $this->createActivity(
            static fn(): array => [],
            static fn(int $id) => null,
            null,
            $rbac
        )->maybePerformAs(6, []);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
    }

    private function createActivity(
        \Closure $lng_objects,
        \Closure $obj_language_factory,
        ?UIFactory $ui_factory = null,
        ?\ilRbacSystem $rbac = null,
        ?\ILIAS\Language\Language $language = null,
        int $language_folder_ref_id = 0
    ): RemoveLocalLanguageChanges {
        return new RemoveLocalLanguageChanges(
            $this->createMock(RefineryFactory::class),
            $ui_factory ?? $this->createMock(UIFactory::class),
            $language ?? $this->createMock(\ILIAS\Language\Language::class),
            $rbac ?? $this->createMock(\ilRbacSystem::class),
            $language_folder_ref_id,
            $lng_objects,
            $obj_language_factory
        );
    }
}

/**
 * Minimal stand-in for \ilObjLanguage: that class cannot be constructed or
 * meaningfully mocked in a unit test (its constructor requires the global
 * $DIC), so tests substitute this fake via RemoveLocalLanguageChanges's
 * $obj_language_factory closure instead. Counts removeLocalChanges()
 * invocations so tests can assert it was never called for a bucket other
 * than "removed"/"invalid".
 */
final class FakeRemoveLocalLanguageChangesObject
{
    private int $remove_local_changes_calls = 0;

    /**
     * @param bool $remove_local_changes_return_value What removeLocalChanges()
     *        returns - true mimics a real, successful removal of local
     *        changes; false mimics \ilObjLanguage::removeLocalChanges()
     *        rejecting the call because the underlying language file failed
     *        check() (isInstalled() having already let it through).
     */
    public function __construct(
        private readonly bool $is_installed,
        private readonly bool $remove_local_changes_return_value = true,
    ) {
    }

    public function isInstalled(): bool
    {
        return $this->is_installed;
    }

    public function removeLocalChanges(): bool
    {
        $this->remove_local_changes_calls++;

        return $this->remove_local_changes_return_value;
    }

    public function removeLocalChangesCallCount(): int
    {
        return $this->remove_local_changes_calls;
    }
}
