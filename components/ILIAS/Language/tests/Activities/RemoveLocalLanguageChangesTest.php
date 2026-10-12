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
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

namespace ILIAS\Language\Tests\Activities;

use ILIAS\Language\Activities\InvalidInputException;
use ILIAS\Language\Activities\RemoveLocalLanguageChanges;
use ILIAS\Language\Activities\SafeToDisplayActivityError;
use ILIAS\Language\Language;
use PHPUnit\Framework\Attributes\DataProvider;

class RemoveLocalLanguageChangesTest extends ActivityWithPerformResultContractTestCase
{
    use RemoveLocalLanguageChangesTestFixture;

    protected function createDefaultActivity(): RemoveLocalLanguageChanges
    {
        [, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld(['de' => []]);

        return $this->createActivity($lng_objects, $obj_language_factory);
    }

    protected function validPerformParameters(): array
    {
        return ['language_keys' => 'de'];
    }

    /**
     * @param array<string, array{installed?: bool, remove_local_changes_return?: bool, unwritten_overlay?: list<string>}> $language_objects
     *        keyed by language key; obj_ids are assigned in iteration order, starting at 1
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
                modules_with_unwritten_overlay: $flags['unwritten_overlay'] ?? [],
            );
            $obj_id++;
        }

        $lng_objects = static fn(): array => $lng_objects_list;
        $obj_language_factory = static fn(int $id): FakeRemoveLocalLanguageChangesObject => $fakes_by_obj_id[$id];

        return [$fakes_by_obj_id, $lng_objects, $obj_language_factory];
    }

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
            $rbac,
            null,
            42
        );

        $this->assertTrue($activity->isAllowedToPerform(6, ['language_keys' => 'de']));
    }

    public function testIsAllowedToPerformReturnsFalseWhenRbacDenies(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(false);

        $activity = $this->createActivity(
            static fn(): array => [],
            static fn(int $id) => null,
            $rbac
        );

        $this->assertFalse($activity->isAllowedToPerform(6, ['language_keys' => 'de']));
    }

    /**
     * isAllowedToPerform() must be a pure permission check: it must never touch $lng_objects or $obj_language_factory.
     */
    public function testIsAllowedToPerformNeverTouchesLngObjectsOrObjectFactory(): void
    {
        $lng_objects = static function (): array {
            throw new \LogicException('lng_objects must never be called by isAllowedToPerform()');
        };
        $obj_language_factory = static function (int $id) {
            throw new \LogicException('obj_language_factory must never be called by isAllowedToPerform()');
        };

        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity($lng_objects, $obj_language_factory, $rbac);

        $this->assertTrue($activity->isAllowedToPerform(6, ['language_keys' => 'de']));
    }

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

    /**
     * A language whose migrated-module overlay could not be written still counts as removed and is also listed in
     * "overlay_write_failed_language_keys".
     */
    public function testALanguageWithAnUnwrittenOverlayIsListedAdditionallyToBeingRemoved(): void
    {
        [, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['unwritten_overlay' => ['pilot']],
            'en' => [],
            'fr' => ['remove_local_changes_return' => false, 'unwritten_overlay' => ['pilot']],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
            'language_keys' => ['de', 'en', 'fr'],
        ]);

        $this->assertSame(['de', 'en'], $result['removed_local_changes_language_keys']);
        $this->assertSame(['fr'], $result['invalid_language_file_keys']);
        $this->assertSame(['de'], $result['overlay_write_failed_language_keys']);
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
     * removeLocalChanges() itself, not just the outer isInstalled() guard, decides whether the language file was valid.
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
        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount());
    }

    /**
     * A language key without "lng" object is reported as not installed; $obj_language_factory is never invoked.
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
     * Languages in every state at once land in their own bucket; removeLocalChanges() runs only for the two
     * installed languages.
     */
    public function testMixedBulkRequestPartitionsEachKeyIntoTheCorrectBucket(): void
    {
        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
            'fr' => ['remove_local_changes_return' => false],
            'it' => ['installed' => false],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory)->perform([
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

    public static function invalidLanguageKeysProvider(): array
    {
        return [
            'missing language_keys key' => [[]],
            'empty (only whitespace/commas)' => [['language_keys' => ' , ']],
            'nested array value' => [['language_keys' => ['de', ['fr']]]],
            'non-array, non-string value' => [['language_keys' => 42]],
            'three letters' => [['language_keys' => 'deu']],
            'one letter' => [['language_keys' => 'd']],
            'uppercase' => [['language_keys' => 'DE']],
            'contains a digit' => [['language_keys' => 'de1']],
            'contains a hyphen' => [['language_keys' => 'de-at']],
        ];
    }

    #[DataProvider('invalidLanguageKeysProvider')]
    public function testInvalidLanguageKeysAreRejected(array $parameters): void
    {
        $this->expectException(InvalidInputException::class);

        $this->createActivity(static fn(): array => [], static fn(int $id) => null)->perform($parameters);
    }

    /**
     * A non-array $parameters must raise the concrete InvalidInputException, like the other Activities.
     */
    public function testNonArrayParametersAreRejected(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->createActivity(static fn(): array => [], static fn(int $id) => null)->perform('not-an-array');
    }

    /**
     * Like UninstallLanguageTest's permission test: a denied permission short-circuits before perform() runs.
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
        $language = $this->createStub(Language::class);
        $language->method('txt')->willReturnMap([
            ['msg_no_perm_write', 'no write permission'],
        ]);

        $result = $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            $rbac,
            $language
        )->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => 'de']);

        $this->assertTrue($result->isError());
        $this->assertSame(0, $fakes[1]->removeLocalChangesCallCount());
    }

    public function testPermissionGrantedPerformsAndReturnsOkResult(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
        ]);

        $result = $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            $rbac
        )->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => 'de']);

        $this->assertFalse($result->isError());
        $this->assertSame(['de'], $result->value()['removed_local_changes_language_keys']);
        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount());
    }

    /**
     * A real GUI caller (class.ilObjLanguageFolderGUI.php) sends 'language_keys' as an array of strings;
     * GrindsFormInput::grind() must join it into a comma-separated string before it reaches the Text field.
     */
    public function testMaybePerformAsAcceptsAnArrayOfLanguageKeysAndRemovesLocalChangesForEachOne(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        [$fakes, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
            'fr' => [],
        ]);

        $result = $this->createActivity($lng_objects, $obj_language_factory, $rbac)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => ['de', 'fr']]);

        $this->assertFalse($result->isError());
        $this->assertSame(['de', 'fr'], $result->value()['removed_local_changes_language_keys']);
        $this->assertSame(1, $fakes[1]->removeLocalChangesCallCount());
        $this->assertSame(1, $fakes[2]->removeLocalChangesCallCount());
    }

    /**
     * A Throwable from within perform() must be turned into a Result\Error by maybePerformAs().
     */
    public function testThrowableFromPerformIsTurnedIntoAResultError(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $lng_objects = static fn(): array => [];
        $obj_language_factory = static fn(int $id) => null;

        // an empty language_keys value throws inside perform(), after the permission check passed
        $result = $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            $rbac
        )->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => ' , ']);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
    }

    /**
     * A missing 'language_keys' key fails the required Text field in grind() itself, before
     * normalizeParameters()/isAllowedToPerform(): the result carries the field's own (string) message.
     */
    public function testMissingLanguageKeysParameterViaMaybePerformAsIsAResultErrorAndNeverChecksPermission(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $result = $this->createActivity(
            static fn(): array => [],
            static fn(int $id) => null,
            $rbac
        )->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, []);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertNotSame('', $result->error()->getMessage());
    }

    /**
     * A Throwable from removeLocalChanges() itself (not a `false` return) is logged as a warning naming "setup update"
     * as the way back, then rethrown.
     */
    public function testRemoveLocalChangesThrowingIsLoggedWithSetupUpdateAdviceAndSurfacesAsAResultError(): void
    {
        $logger = $this->createMock(\ilLogger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('setup.php update'));
        $logger_factory = $this->createStub(\ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $previous_dic = $GLOBALS['DIC'] ?? null;
        $GLOBALS['DIC'] = new \ILIAS\DI\Container();
        $GLOBALS['DIC']['ilLoggerFactory'] = static fn() => $logger_factory;

        $object = new class () {
            public function isInstalled(): bool
            {
                return true;
            }

            public function removeLocalChanges(): bool
            {
                throw new \RuntimeException('db gone');
            }
        };
        $lng_objects = static fn(): array => [['obj_id' => 1, 'title' => 'de']];
        $obj_language_factory = static fn(int $id) => $object;

        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        try {
            $result = $this->createActivity($lng_objects, $obj_language_factory, $rbac)
                ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => 'de']);

            $this->assertTrue($result->isError());
            $this->assertInstanceOf(\RuntimeException::class, $result->error());
        } finally {
            if ($previous_dic === null) {
                unset($GLOBALS['DIC']);
            } else {
                $GLOBALS['DIC'] = $previous_dic;
            }
        }
    }

    /**
     * Plugin language files are re-applied once, only for languages whose local changes were actually removed.
     */
    public function testRefreshPluginsIsCalledOnceWithExactlyTheRemovedLanguageKeys(): void
    {
        [, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => [],
            'en' => [],
            'fr' => ['remove_local_changes_return' => false],
            'it' => ['installed' => false],
        ]);
        $calls = [];

        $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            refresh_plugins: function (array $lang_keys) use (&$calls): void {
                $calls[] = $lang_keys;
            }
        )->perform(['language_keys' => ['de', 'en', 'fr', 'it']]);

        $this->assertSame([['de', 'en']], $calls);
    }

    /**
     * Without a component repository ($DIC is no container or has none) the default re-applying does nothing.
     */
    #[DataProvider('dicWithoutComponentRepository')]
    public function testTheDefaultPluginRefreshDoesNothingWithoutAComponentRepository(\Closure $dic): void
    {
        [, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld(['de' => []]);
        $previous = $GLOBALS['DIC'] ?? null;
        $GLOBALS['DIC'] = $dic();
        try {
            $result = $this->createActivity($lng_objects, $obj_language_factory)->perform(['language_keys' => ['de']]);
        } finally {
            $GLOBALS['DIC'] = $previous;
        }

        $this->assertSame(['de'], $result['removed_local_changes_language_keys']);
    }

    /**
     * @return array<string, array{0: \Closure}>
     */
    public static function dicWithoutComponentRepository(): array
    {
        return [
            'DIC is an array' => [static fn(): array => []],
            'container without component.repository' => [static fn(): \ILIAS\DI\Container => new \ILIAS\DI\Container()],
        ];
    }

    /**
     * Without a single removed language refresh_plugins() is not called at all, not even with an empty list.
     */
    public function testRefreshPluginsIsNeverCalledWhenNoLanguagesLocalChangesWereActuallyRemoved(): void
    {
        [, $lng_objects, $obj_language_factory] = $this->buildFakeLanguageWorld([
            'de' => ['remove_local_changes_return' => false],
            'en' => ['installed' => false],
        ]);
        $calls = [];

        $this->createActivity(
            $lng_objects,
            $obj_language_factory,
            refresh_plugins: function (array $lang_keys) use (&$calls): void {
                $calls[] = $lang_keys;
            }
        )->perform(['language_keys' => ['de', 'en']]);

        $this->assertSame([], $calls);
    }
}
