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

namespace ILIAS\Language\Tests\Activities;

use ILIAS\Language\Tests\Activities\ActivityContractTestCase;
use ILIAS\Language\Activities\AddLanguageEntry;
use ILIAS\Language\Activities\InvalidInputException;
use ILIAS\Language\Activities\SafeToDisplayActivityError;
use ILIAS\Language\Language;
use ILIAS\UI\Component\Input\Field\Text;
use ILIAS\Data\Description\Factory as DescriptionFactory;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\Refinery\String\Group as StringGroup;
use ILIAS\Refinery\String\MarkdownFormattingToHTML;
use ILIAS\UI\Factory as UIFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AddLanguageEntry has no ilSetupLanguage collaborator and no
 * $lng_objects/$obj_language_factory closures (unlike UninstallLanguage/
 * RemoveLocalLanguageChanges) - instead it reads the set of installed
 * languages directly from an injected InstalledLanguageRepository (here a
 * small in-memory fake, never the real InstalledLanguageDatabaseRepository),
 * and writes single entries via the $replace_lang_entry/$update_module_cache
 * closures. Unlike every sibling Activity, it also needs a $user_login
 * closure to resolve the acting user's login for the audit trail (see the
 * class docblock of AddLanguageEntry for why this must go through
 * maybePerformAs() rather than being part of getInputDescription()).
 *
 * None of these tests may touch real language files under lang/*.lang or a
 * real database - every collaborator is a fake/closure/mock.
 */
class AddLanguageEntryTest extends ActivityContractTestCase
{
    protected function createDefaultActivity(): AddLanguageEntry
    {
        return $this->createActivity([]);
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

        $activity = $this->createActivity([], rbac: $rbac, language_folder_ref_id: 42);

        $this->assertTrue($activity->isAllowedToPerform(6, ['module' => 'common', 'identifier' => 'foo']));
    }

    public function testIsAllowedToPerformReturnsFalseWhenRbacDenies(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(false);

        $activity = $this->createActivity([], rbac: $rbac);

        $this->assertFalse($activity->isAllowedToPerform(6, ['module' => 'common', 'identifier' => 'foo']));
    }

    /**
     * isAllowedToPerform() must be a pure permission check: it must never
     * touch the installed language repository, and therefore never call
     * replace_lang_entry()/update_module_cache() - unlike perform(), which is
     * the only place allowed to have side effects.
     */
    public function testIsAllowedToPerformNeverTouchesRepositoryOrWriteClosures(): void
    {
        $repository = new FakeInstalledLanguageRepository(static function (): array {
            throw new \LogicException('getInstalledLanguages() must never be called by isAllowedToPerform()');
        });

        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $replace_lang_entry = static function (): bool {
            throw new \LogicException('replace_lang_entry must never be called by isAllowedToPerform()');
        };
        $update_module_cache = static function (): void {
            throw new \LogicException('update_module_cache must never be called by isAllowedToPerform()');
        };

        $activity = $this->createActivity(
            [],
            rbac: $rbac,
            installed_language_repository: $repository,
            replace_lang_entry: $replace_lang_entry,
            update_module_cache: $update_module_cache
        );

        $this->assertTrue($activity->isAllowedToPerform(6, ['module' => 'common', 'identifier' => 'foo']));
    }

    // -----------------------------------------------------------------
    // perform()
    // -----------------------------------------------------------------

    public function testAddsEntryForEachInstalledLanguageWithANonEmptyValueAndSkipsEmptyOnes(): void
    {
        $calls = [];
        $replace_lang_entry = static function (
            string $module,
            string $identifier,
            string $lang_key,
            string $value,
            string $local_change,
            string $remarks
        ) use (&$calls): bool {
            $calls['replace'][] = [$module, $identifier, $lang_key, $value, $local_change, $remarks];
            return true;
        };
        $update_module_cache = static function (
            string $lang_key,
            string $module,
            string $identifier,
            string $value
        ) use (&$calls): void {
            $calls['cache'][] = [$lang_key, $module, $identifier, $value];
        };
        $user_login = static fn(int $usr_id): string => 'login-' . $usr_id;

        $activity = $this->createActivity(
            ['de', 'en', 'fr'],
            replace_lang_entry: $replace_lang_entry,
            update_module_cache: $update_module_cache,
            user_login: $user_login
        );

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => [
                'de' => 'Hallo',
                'en' => 'Hello',
                // 'fr' left out entirely - must be treated like a blank value.
            ],
            'usr_id' => 6,
        ]);

        $this->assertSame('common', $result['module']);
        $this->assertSame('new_topic', $result['identifier']);
        $this->assertSame(['de', 'en'], $result['added_language_keys']);
        $this->assertSame(['fr'], $result['skipped_empty_language_keys']);

        $this->assertCount(2, $calls['replace']);
        $this->assertSame(['common', 'new_topic', 'de', 'Hallo'], array_slice($calls['replace'][0], 0, 4));
        $this->assertSame('login-6', $calls['replace'][0][5]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $calls['replace'][0][4]);
        $this->assertSame(['common', 'new_topic', 'en', 'Hello'], array_slice($calls['replace'][1], 0, 4));

        $this->assertCount(2, $calls['cache']);
        $this->assertSame(['de', 'common', 'new_topic', 'Hallo'], $calls['cache'][0]);
        $this->assertSame(['en', 'common', 'new_topic', 'Hello'], $calls['cache'][1]);

        // Never touched for the skipped language.
        foreach ($calls['replace'] as $call) {
            $this->assertNotSame('fr', $call[2]);
        }
        foreach ($calls['cache'] as $call) {
            $this->assertNotSame('fr', $call[0]);
        }
    }

    /**
     * Regression test documenting the deliberate, pre-existing limitation
     * described in the class docblock ("Known limitations" - "perform()'s
     * write loop itself is NOT transactional"): if replace_lang_entry()
     * throws while writing language n of m (here: the 3rd of 3, "fr"), the
     * languages already written before it ("de", "en") must remain written
     * - the exception propagates uncaught out of perform() rather than
     * rolling anything back. This is NOT a bug to fix, only a behaviour to
     * pin down so a future change does not silently alter it either way
     * (e.g. by accidentally wrapping the loop in a transaction, or by
     * accidentally swallowing the exception).
     */
    public function testAThrowingReplaceLangEntryPartwayThroughTheWriteLoopLeavesEarlierLanguagesWritten(): void
    {
        $calls = [];
        $replace_lang_entry = static function (
            string $module,
            string $identifier,
            string $lang_key,
            string $value,
            string $local_change,
            string $remarks
        ) use (&$calls): bool {
            $calls['replace'][] = [$module, $identifier, $lang_key, $value, $local_change, $remarks];
            if ($lang_key === 'fr') {
                throw new \RuntimeException('simulated database error while writing "fr"');
            }
            return true;
        };

        $activity = $this->createActivity(
            ['de', 'en', 'fr'],
            replace_lang_entry: $replace_lang_entry,
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        try {
            $activity->perform([
                'module' => 'common',
                'identifier' => 'new_topic',
                'translations' => [
                    'de' => 'Hallo',
                    'en' => 'Hello',
                    'fr' => 'Bonjour',
                ],
                'usr_id' => 6,
            ]);
            $this->fail('Expected the simulated \RuntimeException to propagate out of perform().');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated database error while writing "fr"', $e->getMessage());

            // "de" and "en" - written before the failing "fr" - remain
            // written: perform() gives no atomicity guarantee across the
            // write loop (see class docblock).
            $this->assertSame(
                ['common', 'new_topic', 'de', 'Hallo', $calls['replace'][0][4], 'default-login'],
                $calls['replace'][0]
            );
            $this->assertSame(
                ['common', 'new_topic', 'en', 'Hello', $calls['replace'][1][4], 'default-login'],
                $calls['replace'][1]
            );
            $this->assertSame(['de', 'common', 'new_topic', 'Hallo'], $calls['cache'][0]);
            $this->assertSame(['en', 'common', 'new_topic', 'Hello'], $calls['cache'][1]);

            // Exactly the 3 attempted calls - "fr" itself was attempted
            // (and recorded before throwing) but never reached
            // update_module_cache().
            $this->assertCount(3, $calls['replace']);
            $this->assertCount(2, $calls['cache']);
        }
    }

    // -----------------------------------------------------------------
    // "de"/"en" mandatory, all-or-nothing (see class docblock)
    // -----------------------------------------------------------------

    /**
     * Regression test for the "de"/"en" mandatory, all-or-nothing rule:
     * both installed, "de" given a perfectly valid value, "en" left blank -
     * perform() must reject the WHOLE request and must not write anything
     * for ANY language, not even "de" (whose value was valid).
     */
    public function testDeAndEnBothInstalledOneBlankRejectsWholeRequestAndWritesNothingForAnyLanguage(): void
    {
        $calls = [];
        $activity = $this->createActivity(
            ['de', 'en'],
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        try {
            $activity->perform([
                'module' => 'common',
                'identifier' => 'new_topic',
                'translations' => [
                    'de' => 'Hallo',
                    // 'en' left blank - must reject the whole request.
                ],
                'usr_id' => 6,
            ]);
            $this->fail('Expected an InvalidInputException to be thrown.');
        } catch (InvalidInputException $e) {
            // Nothing written for either language - not even the valid "de".
            $this->assertArrayNotHasKey('replace', $calls);
            $this->assertArrayNotHasKey('cache', $calls);
        }
    }

    /**
     * Same rule, exercised end-to-end through maybePerformAs() (with a
     * real, grinding UI Factory): "de" valid, "en" blank must still reject
     * the whole request as a Result\Error, and must never write anything
     * for "de" either.
     */
    public function testMaybePerformAsRejectsWholeRequestAndWritesNothingWhenEnIsBlankWhileDeIsValid(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $calls = [];
        $activity = $this->createActivity(
            ['de', 'en'],
            rbac: $rbac,
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $result = $activity->maybePerformAs(6, [
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => [
                'de' => 'Hallo',
                'en' => '   ', // whitespace-only counts as blank too
            ],
        ]);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
        $this->assertArrayNotHasKey('replace', $calls);
        $this->assertArrayNotHasKey('cache', $calls);
    }

    /**
     * The mirror case: "en" valid, "de" blank must likewise reject the
     * whole request and write nothing for "en" either - the rule applies
     * to each of "de"/"en" independently, not only to "de".
     */
    public function testPerformRejectsWholeRequestAndWritesNothingWhenDeIsBlankWhileEnIsValid(): void
    {
        $calls = [];
        $activity = $this->createActivity(
            ['de', 'en'],
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $this->expectException(InvalidInputException::class);
        try {
            $activity->perform([
                'module' => 'common',
                'identifier' => 'new_topic',
                'translations' => ['en' => 'Hello'],
                'usr_id' => 6,
            ]);
        } finally {
            $this->assertArrayNotHasKey('replace', $calls);
            $this->assertArrayNotHasKey('cache', $calls);
        }
    }

    /**
     * If neither "de" nor "en" is installed at all, the mandatory rule
     * imposes no requirement for them - exactly like the legacy form,
     * which never rendered a field for a language that is not installed in
     * the first place (see class docblock). Every other installed language
     * remains merely optional.
     */
    public function testDeAndEnNotInstalledAtAllImposesNoMandatoryRequirement(): void
    {
        $calls = [];
        $activity = $this->createActivity(
            ['fr'],
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => [],
            'usr_id' => 6,
        ]);

        $this->assertSame([], $result['added_language_keys']);
        $this->assertSame(['fr'], $result['skipped_empty_language_keys']);
        $this->assertArrayNotHasKey('replace', $calls);
    }

    public function testWhitespaceOnlyValueIsTreatedAsEmptyAndSkipped(): void
    {
        // 'fr' (not 'de'/'en') is used here on purpose: this test isolates
        // the whitespace-only-is-blank behaviour, not the "de"/"en"
        // mandatory rule (see testDeAndEnAreMandatoryAllOrNothing... below).
        $calls = [];
        $activity = $this->createActivity(
            ['fr'],
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['fr' => "  \t "],
            'usr_id' => 6,
        ]);

        $this->assertSame([], $result['added_language_keys']);
        $this->assertSame(['fr'], $result['skipped_empty_language_keys']);
        $this->assertArrayNotHasKey('replace', $calls);
        $this->assertArrayNotHasKey('cache', $calls);
    }

    public function testValueSurroundedByWhitespaceIsTrimmedBeforeBeingWritten(): void
    {
        $calls = [];
        $activity = $this->createActivity(
            ['de'],
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => "  Hallo Welt  "],
            'usr_id' => 6,
        ]);

        $this->assertSame(['de'], $result['added_language_keys']);
        $this->assertSame('Hallo Welt', $calls['replace'][0][3]);
        $this->assertSame('Hallo Welt', $calls['cache'][0][3]);
    }

    /**
     * translations[$lang_key] ?? '' is only ever treated as a candidate
     * value if it is actually a string - a non-string value (e.g. an int,
     * bool or nested array smuggled in through a caller that bypassed
     * normalizeParameters(), such as a direct perform() caller per the
     * README's "As User of a Specific Activity") must be silently treated
     * like an empty value, not cause a TypeError or be coerced/written as-is.
     */
    public function testNonStringTranslationValueIsTreatedAsEmptyAndSkippedRatherThanCoerced(): void
    {
        // 'fr' (not 'de'/'en') is used here on purpose - see
        // testWhitespaceOnlyValueIsTreatedAsEmptyAndSkipped().
        $calls = [];
        $activity = $this->createActivity(
            ['fr'],
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['fr' => 42],
            'usr_id' => 6,
        ]);

        $this->assertSame([], $result['added_language_keys']);
        $this->assertSame(['fr'], $result['skipped_empty_language_keys']);
        $this->assertArrayNotHasKey('replace', $calls);
    }

    public function testNoInstalledLanguagesResultsInEmptyBucketsAndNoWrites(): void
    {
        $calls = [];
        $activity = $this->createActivity(
            [],
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
            'usr_id' => 6,
        ]);

        $this->assertSame([], $result['added_language_keys']);
        $this->assertSame([], $result['skipped_empty_language_keys']);
        $this->assertArrayNotHasKey('replace', $calls);
        $this->assertArrayNotHasKey('cache', $calls);
    }

    public function testUserLoginClosureIsCalledExactlyOnceWithTheGivenUsrIdRegardlessOfLanguageCount(): void
    {
        $login_calls = [];
        $user_login = static function (int $usr_id) use (&$login_calls): string {
            $login_calls[] = $usr_id;
            return 'resolved-login';
        };

        $activity = $this->createActivity(['de', 'en'], user_login: $user_login);

        $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo', 'en' => 'Hello'],
            'usr_id' => 99,
        ]);

        $this->assertSame([99], $login_calls);
    }

    public static function invalidPerformParametersProvider(): array
    {
        return [
            'missing module' => [['identifier' => 'foo', 'translations' => [], 'usr_id' => 6]],
            'empty string module' => [['module' => '', 'identifier' => 'foo', 'translations' => [], 'usr_id' => 6]],
            'non-string module' => [['module' => 42, 'identifier' => 'foo', 'translations' => [], 'usr_id' => 6]],
            'missing identifier' => [['module' => 'common', 'translations' => [], 'usr_id' => 6]],
            'empty string identifier' => [['module' => 'common', 'identifier' => '', 'translations' => [], 'usr_id' => 6]],
            'non-string identifier' => [['module' => 'common', 'identifier' => [], 'translations' => [], 'usr_id' => 6]],
            'missing translations' => [['module' => 'common', 'identifier' => 'foo', 'usr_id' => 6]],
            'non-array translations' => [['module' => 'common', 'identifier' => 'foo', 'translations' => 'nope', 'usr_id' => 6]],
            'missing usr_id' => [['module' => 'common', 'identifier' => 'foo', 'translations' => []]],
            'non-int usr_id' => [['module' => 'common', 'identifier' => 'foo', 'translations' => [], 'usr_id' => '6']],
            'null usr_id' => [['module' => 'common', 'identifier' => 'foo', 'translations' => [], 'usr_id' => null]],
        ];
    }

    #[DataProvider('invalidPerformParametersProvider')]
    public function testPerformRejectsInvalidOrIncompleteParameters(array $parameters): void
    {
        $this->expectException(InvalidInputException::class);

        $this->createActivity(['de'])->perform($parameters);
    }

    public function testPerformRejectsNonArrayParameters(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->createActivity(['de'])->perform('not-an-array');
    }

    // -----------------------------------------------------------------
    // maybePerformAs()
    // -----------------------------------------------------------------

    public function testPermissionDeniedBeforePerformNeverCallsWriteClosuresAndReturnsResultErrorWithNoPermMessage(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->with(6, 'write', $this->anything())
            ->willReturn(false);

        // getInputDescription() (built for real via RealFieldsUiFactory) calls
        // txt('meta_l_de') to label the 'de' translation field before
        // isAllowedToPerform() is ever reached (grinding happens first) - the
        // mock must tolerate that call too, not just 'msg_no_perm_write'.
        $language = $this->createMock(Language::class);
        $language->method('txt')->willReturnCallback(
            static fn(string $key): string => $key === 'msg_no_perm_write' ? 'no write permission' : $key
        );

        $calls = [];
        $activity = $this->createActivity(
            ['de'],
            language: $language,
            rbac: $rbac,
            replace_lang_entry: $this->spyReplaceLangEntry($calls),
            update_module_cache: $this->spyUpdateModuleCache($calls)
        );

        $result = $activity->maybePerformAs(6, [
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
        ]);

        $this->assertTrue($result->isError());
        $this->assertSame('no write permission', $result->error());
        $this->assertArrayNotHasKey('replace', $calls);
    }

    public function testPermissionGrantedPerformsAndReturnsOkResultWithExpectedStructure(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity(['de', 'fr'], rbac: $rbac);

        $result = $activity->maybePerformAs(6, [
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
        ]);

        $this->assertFalse($result->isError());
        $value = $result->value();
        $this->assertSame('common', $value['module']);
        $this->assertSame('new_topic', $value['identifier']);
        $this->assertSame(['de'], $value['added_language_keys']);
        $this->assertSame(['fr'], $value['skipped_empty_language_keys']);
    }

    /**
     * usr_id must never be taken from $raw_parameters (it is not part of
     * normalizeParameters()'s accepted keys - see the class docblock) - it
     * must come exclusively from maybePerformAs()'s own $usr_id argument,
     * both for the permission check and for resolving the audit login.
     */
    public function testUsrIdArgumentIsUsedForBothThePermissionCheckAndTheAuditLoginRegardlessOfRawParameters(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->with(6, 'write', $this->anything())
            ->willReturn(true);

        $login_calls = [];
        $user_login = static function (int $usr_id) use (&$login_calls): string {
            $login_calls[] = $usr_id;
            return 'login';
        };

        $activity = $this->createActivity(['de'], rbac: $rbac, user_login: $user_login);

        $result = $activity->maybePerformAs(6, [
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
            // A caller-supplied usr_id must be ignored entirely.
            'usr_id' => 999,
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame([6], $login_calls);
    }

    /**
     * A Throwable raised from within perform() (e.g. the write closure
     * failing) must be turned into a Result\Error rather than propagating -
     * this is the whole point of maybePerformAs() wrapping perform() in a
     * try/catch.
     */
    public function testThrowableFromWithinPerformIsTurnedIntoAResultError(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $replace_lang_entry = static function (): bool {
            throw new \RuntimeException('database write failed');
        };

        $activity = $this->createActivity(
            ['de'],
            rbac: $rbac,
            replace_lang_entry: $replace_lang_entry
        );

        $result = $activity->maybePerformAs(6, [
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
        ]);

        $this->assertTrue($result->isError());
        $error = $result->error();
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('database write failed', $error->getMessage());
    }

    /**
     * A completely missing 'module' key is caught by grind() itself (see
     * GrindsFormInput): the required Text field receives a blank raw value
     * and fails its own required-field constraint before
     * normalizeParameters()/isAllowedToPerform() are ever reached - so the
     * rejection surfaces as a Result\Error carrying a field-attributed
     * InvalidInputException (see GrindsFormInput::describeInputError()), not
     * an InvalidArgumentException thrown by normalizeParameters(). Either
     * way, the rbac system must never be touched (validation happens first).
     */
    public function testMissingModuleViaMaybePerformAsIsAResultErrorAndNeverChecksPermission(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $activity = $this->createActivity(['de'], rbac: $rbac);

        $result = $activity->maybePerformAs(6, [
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
        ]);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertStringContainsString('module:', $result->error()->getMessage());
    }

    public function testMissingRawParametersKeyIsAResultError(): void
    {
        $activity = $this->createActivity(['de']);

        $result = $activity->maybePerformAs(6, []);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertNotSame('', $result->error()->getMessage());
    }

    /**
     * These raw_parameters all leave the mandatory 'de' translation field
     * (see class docblock, "de"/"en" are mandatory) blank - either because
     * 'translations' is not an array at all, or because it is an array
     * that does not carry a 'de' key - so grind() itself rejects them, via
     * the 'de' Text field's own required-field constraint (or, for a
     * non-string key, GrindsFormInput's own unknown-key guard), before
     * normalizeParameters()/isAllowedToPerform() are ever reached. The
     * rejection surfaces as a Result\Error carrying an InvalidInputException,
     * not an InvalidArgumentException.
     */
    public static function invalidRawParametersRejectedByGrindWithStringErrorProvider(): array
    {
        return [
            'non-array translations' => [['module' => 'common', 'identifier' => 'foo', 'translations' => 'nope']],
            'translations with non-string key' => [
                ['module' => 'common', 'identifier' => 'foo', 'translations' => [0 => 'Hallo']],
            ],
        ];
    }

    #[DataProvider('invalidRawParametersRejectedByGrindWithStringErrorProvider')]
    public function testMaybePerformAsRejectsStructurallyInvalidTranslationsAtGrindLevelWithoutCheckingPermission(
        array $raw_parameters
    ): void {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $activity = $this->createActivity(['de'], rbac: $rbac);

        $result = $activity->maybePerformAs(6, $raw_parameters);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertNotSame('', $result->error()->getMessage());
    }

    public static function invalidRawParametersProvider(): array
    {
        return [
            // 'de' is given a valid, non-blank value here on purpose, so
            // that grind() itself succeeds and the blank module/identifier
            // is instead caught by normalizeParameters()'s
            // toNonEmptyString() - an InvalidInputException, reached
            // before isAllowedToPerform().
            'blank module' => [['module' => '   ', 'identifier' => 'foo', 'translations' => ['de' => 'Hallo']]],
            'blank identifier' => [['module' => 'common', 'identifier' => '   ', 'translations' => ['de' => 'Hallo']]],
            // Outside the Text field's own type check - grind() itself
            // converts the UI framework's own blank \InvalidArgumentException
            // ("Display value does not match input type.") into an
            // InvalidInputException (see grind()'s try/catch).
            'translations with non-string value' => [
                ['module' => 'common', 'identifier' => 'foo', 'translations' => ['de' => 42]],
            ],
        ];
    }

    #[DataProvider('invalidRawParametersProvider')]
    public function testMaybePerformAsRejectsInvalidRawParametersAsResultError(array $raw_parameters): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->never())->method('checkAccessOfUser');

        $activity = $this->createActivity(['de'], rbac: $rbac);

        $result = $activity->maybePerformAs(6, $raw_parameters);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(InvalidInputException::class, $result->error());
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $result->error());
    }

    public function testNormalizeParametersTrimsModuleAndIdentifierButKeepsTranslationsAsGiven(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $calls = [];
        $activity = $this->createActivity(
            ['de'],
            rbac: $rbac,
            replace_lang_entry: $this->spyReplaceLangEntry($calls)
        );

        $activity->maybePerformAs(6, [
            'module' => '  common  ',
            'identifier' => '  new_topic  ',
            'translations' => ['de' => 'Hallo'],
        ]);

        $this->assertSame('common', $calls['replace'][0][0]);
        $this->assertSame('new_topic', $calls['replace'][0][1]);
    }

    /**
     * An empty translations array is a legal input (e.g. a caller merely
     * reserving the module/identifier pair without giving any value yet) -
     * every installed language is then reported skipped, not rejected.
     */
    public function testEmptyTranslationsArrayIsAcceptedAndSkipsEveryInstalledLanguage(): void
    {
        // 'fr'/'it' (not 'de'/'en') are used here on purpose: neither is
        // mandatory, so an entirely empty translations array is legal and
        // every installed language is simply skipped - unlike 'de'/'en',
        // which reject a blank value outright (see the "de"/"en" mandatory
        // regression tests below).
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $activity = $this->createActivity(['fr', 'it'], rbac: $rbac);

        $result = $activity->maybePerformAs(6, [
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => [],
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame([], $result->value()['added_language_keys']);
        $this->assertSame(['fr', 'it'], $result->value()['skipped_empty_language_keys']);
    }

    // -----------------------------------------------------------------
    // getInputDescription()
    // -----------------------------------------------------------------

    public function testInputDescriptionBuildsModuleIdentifierAndPerLanguageTranslationFields(): void
    {
        $module_text = $this->createMock(Text::class);
        $module_text->method('withRequired')->with(true)->willReturnSelf();
        $module_text->method('withDedicatedName')->with('module')->willReturnSelf();

        $identifier_text = $this->createMock(Text::class);
        $identifier_text->method('withRequired')->with(true)->willReturnSelf();
        $identifier_text->method('withDedicatedName')->with('identifier')->willReturnSelf();

        $de_text = $this->createMock(Text::class);
        $de_required = null;
        $de_text->method('withRequired')->willReturnCallback(function (bool $required) use ($de_text, &$de_required) {
            $de_required = $required;
            return $de_text;
        });
        $de_text->method('withDedicatedName')->with('de')->willReturnSelf();

        $fr_text = $this->createMock(Text::class);
        $fr_required = null;
        $fr_text->method('withRequired')->willReturnCallback(function (bool $required) use ($fr_text, &$fr_required) {
            $fr_required = $required;
            return $fr_text;
        });
        $fr_text->method('withDedicatedName')->with('fr')->willReturnSelf();

        $text_calls = [];
        $field = $this->createMock(\ILIAS\UI\Component\Input\Field\Factory::class);
        $field->method('text')->willReturnCallback(
            function (string $label, ?string $byline = null) use (&$text_calls, $module_text, $identifier_text, $de_text, $fr_text) {
                $text_calls[] = $label;
                return match ($label) {
                    'Module' => $module_text,
                    'Identifier' => $identifier_text,
                    'meta_l_de' => $de_text,
                    'meta_l_fr' => $fr_text,
                    default => throw new \LogicException("unexpected text() label: $label"),
                };
            }
        );

        $translations_group = $this->createMock(\ILIAS\UI\Component\Input\Field\Group::class);
        $translations_group->method('withDedicatedName')->with('translations')->willReturnSelf();

        $outer_group = $this->createMock(\ILIAS\UI\Component\Input\Field\Group::class);

        $group_calls = [];
        $field->method('group')->willReturnCallback(
            function (array $fields, ?string $label = null, ?string $byline = null) use (
                &$group_calls,
                $de_text,
                $fr_text,
                $module_text,
                $identifier_text,
                $translations_group,
                $outer_group
            ) {
                $group_calls[] = $fields;
                if ($fields === ['de' => $de_text, 'fr' => $fr_text]) {
                    return $translations_group;
                }
                if ($fields === ['module' => $module_text, 'identifier' => $identifier_text, 'translations' => $translations_group]) {
                    return $outer_group;
                }
                throw new \LogicException('unexpected group() call');
            }
        );

        $input = $this->createMock(\ILIAS\UI\Component\Input\Factory::class);
        $input->method('field')->willReturn($field);

        $ui_factory = $this->createMock(UIFactory::class);
        $ui_factory->method('input')->willReturn($input);

        $language = $this->createMock(Language::class);
        $language->method('txt')->willReturnCallback(static fn(string $key): string => $key);

        $activity = $this->createActivity(
            ['de', 'fr'],
            language: $language,
            ui_factory: $ui_factory
        );

        $this->assertSame($outer_group, $activity->getInputDescription());
        $this->assertSame(['Module', 'Identifier', 'meta_l_de', 'meta_l_fr'], $text_calls);
        // Only 'de' (and 'en', not present here) is required among translations.
        $this->assertTrue($de_required);
        $this->assertFalse($fr_required);
    }

    // -----------------------------------------------------------------
    // getOutputDescription()
    // -----------------------------------------------------------------

    public function testOutputDescriptionDeclaresTheExpectedFourFields(): void
    {
        $string_group = $this->createMock(StringGroup::class);
        $string_group->method('markdown')->willReturn(
            $this->createMock(MarkdownFormattingToHTML::class)
        );
        $refinery = $this->createMock(RefineryFactory::class);
        $refinery->method('string')->willReturn($string_group);

        $activity = $this->createActivity(['de'], refinery: $refinery);

        $description = $activity->getOutputDescription(new DescriptionFactory());

        $field_names = [];
        foreach ($description->getFields() as $field) {
            $field_names[] = $field->getName();
        }

        $this->assertSame(
            ['module', 'identifier', 'added_language_keys', 'skipped_empty_language_keys'],
            $field_names
        );
    }

    // -----------------------------------------------------------------
    // $db / default update_module_cache() guard clauses
    //
    // Every other test in this class overrides $update_module_cache with a
    // no-op or a spy, so the constructor's own $db-backed default closure
    // (see class docblock, "updateModuleCache()'s default needs a database
    // connection...") is never exercised anywhere else. These tests inject
    // a real \ilDBInterface mock instead, to pin down the is_string() guard
    // added around unserialize() - the actual regression this class'
    // constructor gained a $db parameter for. $replace_lang_entry is still
    // overridden with a no-op (writing a single entry is unrelated to the
    // cache refresh and would otherwise need the real global $DIC), so only
    // ONE installed language ("de") with a non-blank value is used - just
    // enough to reach update_module_cache() exactly once per test, without
    // ever reaching \ilObjLanguage::replaceLangModule() (a real write to
    // legacy statics): every case below returns from the guard before that
    // point is reached.
    // -----------------------------------------------------------------

    private function createActivityWithRealUpdateModuleCacheDefault(\ilDBInterface $db): AddLanguageEntry
    {
        return new AddLanguageEntry(
            refinery: $this->createMock(RefineryFactory::class),
            ui_factory: $this->createRealFieldsUiFactory(),
            language: $this->createMock(Language::class),
            rbac_system: $this->createMock(\ilRbacSystem::class),
            installed_language_repository: new FakeInstalledLanguageRepository(static fn(): array => ['de']),
            language_folder_ref_id: 0,
            replace_lang_entry: static fn(
                string $module,
                string $identifier,
                string $lang_key,
                string $value,
                string $local_change,
                string $remarks
            ): bool => true,
            // $update_module_cache deliberately left null - the whole point
            // of these tests is exercising the constructor's own default.
            update_module_cache: null,
            user_login: static fn(int $usr_id): string => 'default-login',
            db: $db,
        );
    }

    private function mockDbFetchingRow(?array $row): \ilDBInterface
    {
        $statement = $this->createMock(\ilDBStatement::class);

        $db = $this->createMock(\ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            static fn(mixed $value, string $type): string => "'" . (string) $value . "'"
        );
        $db->method('query')->willReturn($statement);
        $db->method('fetchAssoc')->with($statement)->willReturn($row);

        return $db;
    }

    public function testDefaultUpdateModuleCacheDoesNothingWhenNoLngModulesRowIsFound(): void
    {
        $db = $this->mockDbFetchingRow(null);

        $activity = $this->createActivityWithRealUpdateModuleCacheDefault($db);

        // No exception/error of any kind must surface - a missing row is a
        // normal, silently-ignored case (see class docblock).
        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
            'usr_id' => 6,
        ]);

        $this->assertSame(['de'], $result['added_language_keys']);
    }

    public function testDefaultUpdateModuleCacheDoesNothingWhenLangArrayColumnIsNull(): void
    {
        // Before the is_string() guard was added, unserialize(null, ...)
        // would raise a \TypeError under declare(strict_types=1) instead of
        // silently doing nothing (see class docblock/AddLanguageEntry.php).
        $db = $this->mockDbFetchingRow(['lang_array' => null]);

        $activity = $this->createActivityWithRealUpdateModuleCacheDefault($db);

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
            'usr_id' => 6,
        ]);

        $this->assertSame(['de'], $result['added_language_keys']);
    }

    public function testDefaultUpdateModuleCacheDoesNothingWhenLangArrayColumnIsNotAString(): void
    {
        $db = $this->mockDbFetchingRow(['lang_array' => 42]);

        $activity = $this->createActivityWithRealUpdateModuleCacheDefault($db);

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
            'usr_id' => 6,
        ]);

        $this->assertSame(['de'], $result['added_language_keys']);
    }

    public function testDefaultUpdateModuleCacheDoesNothingWhenLangArrayColumnDeserializesToANonArray(): void
    {
        // A syntactically valid serialized string that decodes to something
        // other than an array (e.g. a plain scalar) leaves nothing to merge
        // the new entry into - silently do nothing, same as a missing row.
        $db = $this->mockDbFetchingRow(['lang_array' => serialize('not-an-array')]);

        $activity = $this->createActivityWithRealUpdateModuleCacheDefault($db);

        $result = $activity->perform([
            'module' => 'common',
            'identifier' => 'new_topic',
            'translations' => ['de' => 'Hallo'],
            'usr_id' => 6,
        ]);

        $this->assertSame(['de'], $result['added_language_keys']);
    }

    // -----------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string, array> $calls
     */
    private function spyReplaceLangEntry(array &$calls): \Closure
    {
        return static function (
            string $module,
            string $identifier,
            string $lang_key,
            string $value,
            string $local_change,
            string $remarks
        ) use (&$calls): bool {
            $calls['replace'][] = [$module, $identifier, $lang_key, $value, $local_change, $remarks];
            return true;
        };
    }

    /**
     * @param array<string, array> $calls
     */
    private function spyUpdateModuleCache(array &$calls): \Closure
    {
        return static function (
            string $lang_key,
            string $module,
            string $identifier,
            string $value
        ) use (&$calls): void {
            $calls['cache'][] = [$lang_key, $module, $identifier, $value];
        };
    }

    /**
     * @param list<string> $installed_languages
     */
    private function createActivity(
        array $installed_languages,
        ?UIFactory $ui_factory = null,
        ?\ilRbacSystem $rbac = null,
        ?Language $language = null,
        ?InstalledLanguageRepository $installed_language_repository = null,
        \ilDBInterface|\Closure|null $db = null,
        int $language_folder_ref_id = 0,
        ?\Closure $replace_lang_entry = null,
        ?\Closure $update_module_cache = null,
        ?\Closure $user_login = null,
        ?RefineryFactory $refinery = null,
    ): AddLanguageEntry {
        // Benign no-op defaults: user_login() is invoked unconditionally by
        // perform() (even when every installed language is skipped), so it
        // must always have a safe default. replace_lang_entry()/
        // update_module_cache() default to harmless no-ops too - never the
        // real \ilObjLanguage-backed closures, which would need the real
        // global $DIC. Tests asserting these must never be called (e.g.
        // isAllowedToPerform() tests) pass their own throwing closures
        // explicitly instead of relying on this default.
        $replace_lang_entry ??= static fn(
            string $module,
            string $identifier,
            string $lang_key,
            string $value,
            string $local_change,
            string $remarks
        ): bool => true;
        $update_module_cache ??= static function (
            string $lang_key,
            string $module,
            string $identifier,
            string $value
        ): void {
        };
        $user_login ??= static fn(int $usr_id): string => 'default-login';
        // Every test above passes its own $update_module_cache (a no-op or a
        // spy), so the constructor's own $db-based default closure is never
        // reached in practice - this deliberately throws rather than
        // defaulting to a harmless stub, so a test that accidentally relies
        // on the real default fails loudly instead of silently passing.
        $db ??= static fn(): \ilDBInterface => throw new \LogicException(
            'db must not be resolved when update_module_cache is overridden'
        );

        return new AddLanguageEntry(
            refinery: $refinery ?? $this->createMock(RefineryFactory::class),
            ui_factory: $ui_factory ?? $this->createRealFieldsUiFactory(),
            language: $language ?? $this->createMock(Language::class),
            rbac_system: $rbac ?? $this->createMock(\ilRbacSystem::class),
            installed_language_repository: $installed_language_repository ?? new FakeInstalledLanguageRepository(
                static fn(): array => $installed_languages
            ),
            language_folder_ref_id: $language_folder_ref_id,
            replace_lang_entry: $replace_lang_entry,
            update_module_cache: $update_module_cache,
            user_login: $user_login,
            db: $db,
        );
    }
}

/**
 * Minimal in-memory stand-in for InstalledLanguageRepository:
 * InstalledLanguageDatabaseRepository (the real implementation) needs an
 * actual database connection, which unit tests must never construct. Only
 * getInstalledLanguages() is exercised by AddLanguageEntry; every other
 * method is unreachable from it and simply throws if a test ever calls it by
 * mistake.
 */
final class FakeInstalledLanguageRepository implements InstalledLanguageRepository
{
    public function __construct(
        private readonly \Closure $installed_languages,
    ) {
    }

    public function getInstalledLanguages(): array
    {
        return ($this->installed_languages)();
    }

    public function getInstalledLocalLanguages(): array
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function getAvailableLanguages(): array
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function getLocalChanges(string $lang_key, string $min_date = "", string $max_date = ""): array
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function getLanguageEntries(string $lang_key): array
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function getLocalLanguages(): array
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function getInstallableLanguages(): array
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function getInvalidLocalLanguageFiles(array $language_keys): array
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function checkLanguage(string $lang_key): bool
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }

    public function checkLocalLanguageFile(string $lang_key): bool
    {
        throw new \LogicException(__METHOD__ . ' is not used by AddLanguageEntry.');
    }
}
