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

use ILIAS\Language\Tests\Activities\ActivityWithPerformResultContractTestCase;
use ILIAS\Language\Activities\InvalidInputException;
use ILIAS\Language\Activities\UpdateLanguage;
use ILIAS\UI\Component\Input\Field\Text;
use ILIAS\UI\Component\Input\Field\Group;
use ILIAS\Language\Language;
use ILIAS\Refinery\Factory as RefineryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use ilSetupLanguage;

class UpdateLanguageTest extends ActivityWithPerformResultContractTestCase
{
    protected function createDefaultActivity(): UpdateLanguage
    {
        $setup_language = $this->createSetupLanguageStub([], [], ['de']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);

        return $this->createActivity($setup_language);
    }

    protected function validPerformParameters(): array
    {
        return ['language_keys' => 'de'];
    }

    /**
     * UpdateLanguage passes exactly the language key. Captures the raw arguments, as ->with('de') would also
     * accept an additional one.
     */
    public function testPassesOnlyTheLanguageKeyToInsertLanguageForInstallation(): void
    {
        $setup_language = $this->createSetupLanguageStub([], [], ['de']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $calls = [];
        $setup_language->method('insertLanguageForInstallation')->willReturnCallback(
            static function () use (&$calls): array {
                $calls[] = func_get_args();
                return [];
            }
        );

        $this->createActivity($setup_language)->perform(['language_keys' => 'de']);

        $this->assertSame([['de']], $calls);
    }

    /**
     * A non-empty return of insertLanguageForInstallation() (migrated modules whose overlay could not be written)
     * lists the language, which still counts as updated.
     */
    public function testListsOnlyTheLanguagesWhoseOverlayCouldNotBeWritten(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de', 'fr']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $setup_language->expects($this->exactly(2))->method('insertLanguageForInstallation')->willReturnMap([
            ['de', []],
            ['fr', ['pilot']],
        ]);

        $result = $this->createActivity($setup_language)->perform(['language_keys' => 'de,fr,it']);

        $this->assertSame(['de', 'fr'], $result['updated_language_keys']);
        $this->assertSame(['it'], $result['not_installed_language_keys']);
        $this->assertSame(['fr'], $result['overlay_write_failed_language_keys']);
    }

    public function testNothingIsListedWhenEveryOverlayWasWritten(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $setup_language->expects($this->once())->method('insertLanguageForInstallation')->with('de')->willReturn([]);

        $result = $this->createActivity($setup_language)->perform(['language_keys' => 'de']);

        $this->assertSame([], $result['overlay_write_failed_language_keys']);
    }

    /**
     * The update also re-applies the plugin language files, once and only for languages actually updated.
     */
    public function testReappliesThePluginLanguageFilesForTheUpdatedLanguagesOnly(): void
    {
        $setup_language = $this->createSetupLanguageStub([], [], ['de', 'fr']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $refreshed = [];
        $refresh_plugins = static function (array $lang_keys) use (&$refreshed): void {
            $refreshed[] = $lang_keys;
        };

        $this->createActivity($setup_language, null, null, $refresh_plugins)->perform(['language_keys' => 'de,fr,it']);
        $this->assertSame([['de', 'fr']], $refreshed);

        $refreshed = [];
        $nothing_installed = $this->createSetupLanguageStub([], [], []);
        $this->createActivity($nothing_installed, null, null, $refresh_plugins)->perform(['language_keys' => 'de']);
        $this->assertSame([], $refreshed, 'nothing updated, nothing to re-apply');
    }

    /**
     * Without a component repository (Setup: $DIC is no container or has none) re-applying does nothing.
     *
     * @param \Closure(): mixed $dic
     */
    #[DataProvider('dicWithoutComponentRepository')]
    public function testTheDefaultPluginRefreshDoesNothingWithoutAComponentRepository(\Closure $dic): void
    {
        $setup_language = $this->createSetupLanguageStub([], [], ['de']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $previous = $GLOBALS['DIC'] ?? null;
        $GLOBALS['DIC'] = $dic();
        try {
            $result = $this->createActivity($setup_language)->perform(['language_keys' => 'de']);
            $setup_result = UpdateLanguage::forSetup($setup_language)->perform(['language_keys' => 'de']);
        } finally {
            $GLOBALS['DIC'] = $previous;
        }

        $this->assertSame(['de'], $result['updated_language_keys']);
        $this->assertSame(['de'], $setup_result['updated_language_keys']);
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
     * The Setup instance never re-applies the plugin language files (the plugin objectives do), even where $DIC
     * offers a component repository.
     */
    public function testTheSetupInstanceNeverRefreshesPlugins(): void
    {
        $setup_language = $this->createSetupLanguageStub([], [], ['de']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $dic = new \ILIAS\DI\Container();
        $dic['component.repository'] = static fn(): never => throw new \LogicException('plugins must not be refreshed in the Setup');
        $previous = $GLOBALS['DIC'] ?? null;
        $GLOBALS['DIC'] = $dic;
        try {
            $result = UpdateLanguage::forSetup($setup_language)->perform(['language_keys' => 'de']);
        } finally {
            $GLOBALS['DIC'] = $previous;
        }

        $this->assertSame(['de'], $result['updated_language_keys']);
    }

    public function testSingleAlreadyInstalledLanguageIsRefreshed(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de']);
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('registerInstalledLanguage')->with(
            'de',
            [],
            []
        );

        $result = $this->createActivity($setup_language)->perform([
            'language_keys' => ' de ',
        ]);

        $this->assertSame(['de'], $result['updated_language_keys']);
        $this->assertSame([], $result['not_installed_language_keys']);
    }

    /**
     * A language that is not installed is a complete no-op, not even checkLanguageForInstallation may run;
     * InstallLanguage installs it.
     */
    public function testSingleNotInstalledLanguageIsCompleteNoOp(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], []);
        $setup_language->expects($this->never())->method('checkLanguageForInstallation');
        $setup_language->expects($this->never())->method('flushLanguageForInstallation');
        $setup_language->expects($this->never())->method('insertLanguageForInstallation');
        $setup_language->expects($this->never())->method('registerInstalledLanguage');

        $result = $this->createActivity($setup_language)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['updated_language_keys']);
        $this->assertSame(['de'], $result['not_installed_language_keys']);
    }

    /**
     * Installed and not installed languages at once land in their own bucket; only the installed one triggers a write.
     */
    public function testMixedListPartitionsEachKeyIntoTheCorrectBucket(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de']);
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('registerInstalledLanguage')->with('de', [], []);

        $result = $this->createActivity($setup_language)->perform([
            'language_keys' => ['de', 'fr'],
        ]);

        $this->assertSame(['de'], $result['updated_language_keys']);
        $this->assertSame(['fr'], $result['not_installed_language_keys']);
    }

    /**
     * getAvailableLanguagesForInstallation() and getLocalLanguages() must reach registerInstalledLanguage() in this
     * order; distinguishable shapes make a swap visible.
     */
    public function testRegisterInstalledLanguageReceivesKnownLanguagesAndLocalLanguageKeysInTheCorrectOrder(): void
    {
        $setup_language = $this->createSetupLanguageMock(
            ['en' => ['obj_id' => 1, 'status' => 'installed']], // $db_languages
            ['fr'], // $local_language_keys
            ['de'] // installed
        );
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $setup_language->expects($this->once())
            ->method('registerInstalledLanguage')
            ->with(
                'de',
                ['en' => ['obj_id' => 1, 'status' => 'installed']],
                ['fr']
            );

        $this->createActivity($setup_language)->perform([
            'language_keys' => 'de',
        ]);
    }

    public function testInvalidLanguageFileOfAnAlreadyInstalledLanguagePreventsRefreshAndThrows(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de']);
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(false);
        $setup_language->expects($this->never())->method('flushLanguageForInstallation');
        $setup_language->expects($this->never())->method('insertLanguageForInstallation');
        $setup_language->expects($this->never())->method('registerInstalledLanguage');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid language files: de');

        $this->createActivity($setup_language)->perform([
            'language_keys' => 'de',
        ]);
    }

    /**
     * All invalid keys among the refreshed ones are collected into one error; the not-installed key is never validated.
     */
    public function testMultipleInvalidLanguageFilesAreAllReportedTogether(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de', 'xx']);
        $setup_language->expects($this->exactly(2))
            ->method('checkLanguageForInstallation')
            ->willReturnMap([
                ['de', false],
                ['xx', false],
            ]);
        $setup_language->expects($this->never())->method('flushLanguageForInstallation');
        $setup_language->expects($this->never())->method('insertLanguageForInstallation');
        $setup_language->expects($this->never())->method('registerInstalledLanguage');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid language files: de, xx');

        $this->createActivity($setup_language)->perform([
            'language_keys' => ['de', 'xx', 'fr'], // 'fr' is not installed - untouched
        ]);
    }

    /**
     * If every requested language is not installed, perform() must not read the available/local languages.
     */
    public function testAllRequestedLanguagesNotInstalledSkipsLanguageLookupsEntirely(): void
    {
        $setup_language = $this->createMock(ilSetupLanguage::class);
        $setup_language->method('getInstalledLanguages')->willReturn([]);
        $setup_language->expects($this->never())->method('getAvailableLanguagesForInstallation');
        $setup_language->expects($this->never())->method('getLocalLanguages');
        $setup_language->expects($this->never())->method('checkLanguageForInstallation');
        $setup_language->expects($this->never())->method('flushLanguageForInstallation');
        $setup_language->expects($this->never())->method('insertLanguageForInstallation');
        $setup_language->expects($this->never())->method('registerInstalledLanguage');

        $result = $this->createActivity($setup_language)->perform([
            'language_keys' => ['de', 'en'],
        ]);

        $this->assertSame([], $result['updated_language_keys']);
        $this->assertSame(['de', 'en'], $result['not_installed_language_keys']);
    }

    /**
     * UpdateLanguage must never look at whether a local file is invalid (InstallLanguage mode "install_local" does).
     */
    public function testNeverInspectsInvalidLocalLanguageFiles(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);
        $setup_language->expects($this->never())->method('getInvalidLocalLanguageFiles');

        $this->createActivity($setup_language)->perform([
            'language_keys' => 'de',
        ]);
    }

    public function testDuplicateLanguageKeysAcrossStringAndArrayAreDeduplicated(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], ['de']);
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('registerInstalledLanguage');

        $result = $this->createActivity($setup_language)->perform([
            'language_keys' => [' de, de ', 'de'],
        ]);

        $this->assertSame(['de'], $result['updated_language_keys']);
    }

    public static function invalidLanguageKeysProvider(): array
    {
        return [
            'missing language_keys key' => [[]],
            'empty (only whitespace/commas)' => [['language_keys' => ' , ']],
            'nested array value' => [['language_keys' => ['de', ['fr']]]],
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

        $this->createActivity($this->createSetupLanguageStub([], [], []))->perform($parameters);
    }

    /**
     * A non-array $parameters must raise the concrete InvalidInputException, not just \InvalidArgumentException.
     */
    public function testNonArrayParametersAreRejected(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->createActivity($this->createSetupLanguageStub([], [], []))->perform('not-an-array');
    }

    public function testInputDescriptionUsesOnlyTheLanguageKeysFieldWithNoModeField(): void
    {
        $text = $this->createMock(Text::class);
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
        // no mode parameter: the select field must never be built
        $field->expects($this->never())->method('select');

        $group = $this->createStub(Group::class);
        $field->expects($this->once())
            ->method('group')
            ->with(['language_keys' => $text])
            ->willReturn($group);

        $activity = $this->createActivity(
            $this->createSetupLanguageStub([], [], [])
        );

        $this->assertSame($group, $activity->getInputDescription($field));
    }

    public function testPermissionDeniedBeforePerform(): void
    {
        $rbac = $this->createMock(\ilRbacSystem::class);
        $rbac->expects($this->once())
            ->method('checkAccessOfUser')
            ->with(
                6,
                'write',
                $this->anything()
            )
            ->willReturn(false);

        $setup_language = $this->createSetupLanguageMock([], [], ['de']);
        $setup_language->expects($this->never())->method('getInstalledLanguages');
        $setup_language->expects($this->never())->method('flushLanguageForInstallation');
        $language = $this->createStub(Language::class);
        $language->method('txt')->willReturnMap([
            ['msg_no_perm_write', 'no write permission'],
        ]);

        $result = $this->createActivity(
            $setup_language,
            $rbac,
            $language
        )->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => 'de']);

        $this->assertTrue($result->isError());
    }

    public function testPermissionGrantedPerformsAndReturnsOkResult(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $setup_language = $this->createSetupLanguageStub([], [], ['de']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);

        $result = $this->createActivity(
            $setup_language,
            $rbac
        )->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => 'de']);

        $this->assertFalse($result->isError());
        $this->assertSame(['de'], $result->value()['updated_language_keys']);
    }

    /**
     * A real GUI caller (class.ilObjLanguageFolderGUI.php) sends 'language_keys' as an array of strings;
     * GrindsFormInput::grind() must join it into a comma-separated string before it reaches the Text field.
     */
    public function testMaybePerformAsAcceptsAnArrayOfLanguageKeysAndUpdatesEachOne(): void
    {
        $rbac = $this->createStub(\ilRbacSystem::class);
        $rbac->method('checkAccessOfUser')->willReturn(true);

        $setup_language = $this->createSetupLanguageStub([], [], ['de', 'fr']);
        $setup_language->method('checkLanguageForInstallation')->willReturn(true);

        $result = $this->createActivity($setup_language, $rbac)
            ->maybePerformAs($this->createRealFieldsUiFactory()->input(), 6, ['language_keys' => ['de', 'fr']]);

        $this->assertFalse($result->isError());
        $this->assertSame(['de', 'fr'], $result->value()['updated_language_keys']);
    }

    private function createActivity(
        ilSetupLanguage $setup_language,
        ?\ilRbacSystem $rbac = null,
        ?Language $language = null,
        ?\Closure $refresh_plugins = null
    ): UpdateLanguage {
        return new UpdateLanguage(
            $this->createStub(RefineryFactory::class),
            $language ?? $this->createStub(Language::class),
            $this->rbacOf($rbac ?? $this->createStub(\ilRbacSystem::class)),
            $setup_language,
            $this->languageFolder(),
            $refresh_plugins
        );
    }

    private function createSetupLanguageMock(
        array $available_languages,
        array $local_language_keys,
        array $installed_language_keys
    ): MockObject&ilSetupLanguage {
        $setup_language = $this->createMock(ilSetupLanguage::class);
        $setup_language->method('getAvailableLanguagesForInstallation')->willReturn($available_languages);
        $setup_language->method('getLocalLanguages')->willReturn($local_language_keys);
        $setup_language->method('getInstalledLanguages')->willReturn($installed_language_keys);

        return $setup_language;
    }

    /**
     * Stub variant of createSetupLanguageMock() for call sites without ->expects(): a plain createMock() there
     * triggers PHPUnit's "no expectations configured" notice.
     *
     * @param array<mixed> $available_languages
     * @param array<mixed> $local_language_keys
     * @param array<mixed> $installed_language_keys
     */
    private function createSetupLanguageStub(
        array $available_languages,
        array $local_language_keys,
        array $installed_language_keys
    ): Stub&ilSetupLanguage {
        $setup_language = $this->createStub(ilSetupLanguage::class);
        $setup_language->method('getAvailableLanguagesForInstallation')->willReturn($available_languages);
        $setup_language->method('getLocalLanguages')->willReturn($local_language_keys);
        $setup_language->method('getInstalledLanguages')->willReturn($installed_language_keys);

        return $setup_language;
    }
}
