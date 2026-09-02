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

use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\Language\Activities\InstallLanguage;
use ILIAS\UI\Factory as UIFactory;
use PHPUnit\Framework\MockObject\MockObject;

class ilInstallLanguageTest extends ilLanguageBaseTestCase
{
    public function testSingleNewLanguageIsInstalled(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], []);
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation');

        $result = $this->createActivity($setup_language, $this->createDatabaseMock())->perform([
            'language_keys' => ' de ',
        ]);

        $this->assertSame(['de'], $result['installed_language_keys']);
        $this->assertSame([], $result['installed_with_local_language_keys']);
        $this->assertSame([], $result['already_installed_language_keys']);
        $this->assertSame([], $result['invalid_local_language_files']);
    }

    public function testMultipleLanguagesAndAlreadyInstalledLanguageAreSeparated(): void
    {
        $setup_language = $this->createSetupLanguageMock(
            [
                'de' => ['obj_id' => 1, 'status' => 'installed'],
                'fr' => ['obj_id' => 2, 'status' => 'not_installed'],
            ],
            [],
            ['de']
        );
        $setup_language->expects($this->exactly(2))
            ->method('checkLanguageForInstallation')
            ->willReturn(true);
        $setup_language->expects($this->exactly(2))->method('flushLanguageForInstallation');
        $setup_language->expects($this->exactly(2))->method('insertLanguageForInstallation');

        $result = $this->createActivity($setup_language, $this->createDatabaseMock())->perform([
            'language_keys' => [' de, fr ', 'de'],
        ]);

        $this->assertSame(['fr'], $result['installed_language_keys']);
        $this->assertSame([], $result['installed_with_local_language_keys']);
        $this->assertSame(['de'], $result['already_installed_language_keys']);
        $this->assertSame([], $result['invalid_local_language_files']);
    }

    public function testNewLanguageWithCustomFileIsReportedAsInstalledWithLocalFile(): void
    {
        // Even a language that was not installed before must be classified
        // by "has a customizing/local file", not "was newly installed" - a
        // fresh language that already ships a local file is still more
        // accurately described as "installed with custom file" than as a
        // plain install.
        $setup_language = $this->createSetupLanguageMock(
            [],
            ['de'],
            []
        );
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation')->with('de');

        $result = $this->createActivity($setup_language, $this->createDatabaseMock())->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame([], $result['installed_language_keys']);
        $this->assertSame(['de'], $result['installed_with_local_language_keys']);
        $this->assertSame([], $result['already_installed_language_keys']);
    }

    public function testInstalledLanguageCanBeReinstalledWithCustomLanguageFile(): void
    {
        $setup_language = $this->createSetupLanguageMock(
            [
                'de' => ['obj_id' => 1, 'status' => 'installed'],
            ],
            ['de'],
            ['de']
        );
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation')->with('de');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation')->with('de');
        // The object_data bookkeeping itself is delegated to
        // ilSetupLanguage::registerInstalledLanguage() (shared with
        // installLanguages()) - see ilSetupLanguageTest for coverage of the
        // actual INSERT/UPDATE SQL it builds.
        $setup_language->expects($this->once())
            ->method('registerInstalledLanguage')
            ->with(
                $this->anything(),
                'de',
                ['de' => ['obj_id' => 1, 'status' => 'installed']],
                ['de']
            );

        $db = $this->createDatabaseMock();

        $result = $this->createActivity($setup_language, $db)->perform([
            'language_keys' => 'de',
        ]);

        // 'de' has a customizing/local file, which is (re-)applied on every
        // run regardless of the language object's own install status -
        // "already installed" alone would be misleading feedback since
        // something did change, so it must land in its own bucket instead.
        $this->assertSame([], $result['installed_language_keys']);
        $this->assertSame(['de'], $result['installed_with_local_language_keys']);
        $this->assertSame([], $result['already_installed_language_keys']);
        $this->assertSame([], $result['invalid_local_language_files']);
    }

    public function testInstallingNewLanguageDoesNotUpdateAlreadyInstalledLanguages(): void
    {
        $setup_language = $this->createSetupLanguageMock(
            [
                'en' => ['obj_id' => 1, 'status' => 'installed'],
            ],
            [],
            ['en']
        );
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation');
        $setup_language->expects($this->once())
            ->method('registerInstalledLanguage')
            ->with(
                $this->anything(),
                'de',
                ['en' => ['obj_id' => 1, 'status' => 'installed']],
                []
            );

        $db = $this->createDatabaseMock();
        $result = $this->createActivity($setup_language, $db)->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame(['de'], $result['installed_language_keys']);
        $this->assertSame([], $result['installed_with_local_language_keys']);
        $this->assertSame([], $result['already_installed_language_keys']);
        $this->assertSame([], $result['invalid_local_language_files']);
    }

    public function testMalformedLocalLanguageFileDoesNotPreventStandardInstallation(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], [], ['ilias_de.lang.locl']);
        $setup_language->expects($this->once())
            ->method('getInvalidLocalLanguageFiles')
            ->with(['de'])
            ->willReturn(['ilias_de.lang.locl']);
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('de')
            ->willReturn(true);
        $setup_language->expects($this->once())->method('flushLanguageForInstallation');
        $setup_language->expects($this->once())->method('insertLanguageForInstallation');

        $result = $this->createActivity($setup_language, $this->createDatabaseMock())->perform([
            'language_keys' => 'de',
        ]);

        $this->assertSame(['de'], $result['installed_language_keys']);
        $this->assertSame(['ilias_de.lang.locl'], $result['invalid_local_language_files']);
    }

    public function testInvalidLanguageFileIsReportedAsActivityError(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], []);
        $setup_language->expects($this->once())
            ->method('checkLanguageForInstallation')
            ->with('xx')
            ->willReturn(false);
        $setup_language->expects($this->never())->method('flushLanguageForInstallation');
        $setup_language->expects($this->never())->method('insertLanguageForInstallation');

        $this->expectException(RuntimeException::class);
        $this->createActivity($setup_language)->perform([
            'language_keys' => 'xx',
        ]);
    }

    public function testMixedValidAndInvalidLanguagesDoNotMutate(): void
    {
        $setup_language = $this->createSetupLanguageMock([], [], []);
        $setup_language->expects($this->exactly(2))
            ->method('checkLanguageForInstallation')
            ->willReturnMap([
                ['de', true],
                ['xx', false],
            ]);
        $setup_language->expects($this->never())->method('flushLanguageForInstallation');
        $setup_language->expects($this->never())->method('insertLanguageForInstallation');

        $this->expectException(RuntimeException::class);
        $this->createActivity($setup_language)->perform([
            'language_keys' => 'de,xx',
        ]);
    }

    public function testInputDescriptionUsesNamedLanguageKeysField(): void
    {
        $text = $this->createMock(\ILIAS\UI\Component\Input\Field\Text::class);
        $text->expects($this->once())->method('withRequired')->with(true)->willReturnSelf();
        $text->expects($this->once())
            ->method('withDedicatedName')
            ->with('language_keys')
            ->willReturnSelf();

        $field = $this->createMock(\ILIAS\UI\Component\Input\Field\Factory::class);
        $field->expects($this->once())->method('text')->with(
            'Sprachschlüssel',
            'Kommagetrennte Liste von Sprachschlüsseln, z. B. de, fr, it.'
        )->willReturn($text);

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
            $this->createSetupLanguageMock([], [], []),
            $this->createDatabaseMock(),
            $ui_factory
        );

        $this->assertSame($group, $activity->getInputDescription());
    }

    public function testEmptyLanguageKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createActivity($this->createSetupLanguageMock([], [], []))->perform([
            'language_keys' => ' , ',
        ]);
    }

    public function testInvalidLanguageKeysTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createActivity($this->createSetupLanguageMock([], [], []))->perform([
            'language_keys' => ['de', ['fr']],
        ]);
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

        $setup_language = $this->createSetupLanguageMock([], [], []);
        $setup_language->expects($this->never())->method('getAvailableLanguagesForInstallation');
        $language = $this->createMock(\ILIAS\Language\Language::class);
        $language->method('txt')->with('msg_no_perm_write')->willReturn('no write permission');

        $result = $this->createActivity(
            $setup_language,
            $this->createDatabaseMock(),
            null,
            $rbac,
            $language
        )->maybePerformAs(6, ['language_keys' => 'de']);

        $this->assertTrue($result->isError());
    }

    private function createActivity(
        ilSetupLanguage $setup_language,
        ?ilDBInterface $db = null,
        ?UIFactory $ui_factory = null,
        ?\ilRbacSystem $rbac = null,
        ?\ILIAS\Language\Language $language = null
    ): InstallLanguage {
        return new InstallLanguage(
            $this->createMock(RefineryFactory::class),
            $ui_factory ?? $this->createMock(UIFactory::class),
            $language ?? $this->createMock(\ILIAS\Language\Language::class),
            $rbac ?? $this->createMock(\ilRbacSystem::class),
            $db ?? $this->createDatabaseMock(),
            $setup_language
        );
    }

    private function createSetupLanguageMock(
        array $available_languages,
        array $local_language_keys,
        array $installed_language_keys,
        array $invalid_local_language_files = []
    ): MockObject&ilSetupLanguage {
        $setup_language = $this->createMock(ilSetupLanguage::class);
        $setup_language->method('getAvailableLanguagesForInstallation')->willReturn($available_languages);
        $setup_language->method('getLocalLanguages')->willReturn($local_language_keys);
        $setup_language->method('getInstalledLanguages')->willReturn($installed_language_keys);
        $setup_language->method('getInvalidLocalLanguageFiles')->willReturn($invalid_local_language_files);

        return $setup_language;
    }

    private function createDatabaseMock(): MockObject&ilDBInterface
    {
        $db = $this->createMock(ilDBInterface::class);
        $db->method('nextId')->willReturn(1);
        $db->method('quote')->willReturnCallback(
            static fn(mixed $value): string => "'" . (string) $value . "'"
        );
        $db->method('now')->willReturn('NOW()');
        $db->method('manipulate')->willReturn(1);

        return $db;
    }
}
