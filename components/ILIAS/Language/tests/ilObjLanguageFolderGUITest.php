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

use ILIAS\Language\Activities\InstallLanguage;
use ILIAS\Data\Result\Error as ResultError;
use ILIAS\Data\Result\Ok as ResultOk;
use PHPUnit\Framework\TestCase;

/**
 * ilObjLanguageFolderGUI is bound tightly to the legacy $DIC/ilObjectGUI
 * bootstrap (its constructor alone touches ilCtrl, ilTemplate, ilObjUser,
 * ilRbac*, request wrappers, ...), so building a real instance is not
 * practical for a unit test. installObject() itself is reachable in
 * isolation, though: constructed via
 * ReflectionClass::newInstanceWithoutConstructor() plus reflection-injected
 * collaborators (a legitimate legacy-code testing technique - see
 * docs/development or "Characterization Tests" practice), and called with
 * an empty $ids array so the method's only other piece of legacy coupling
 * (`new ilObjLanguage((int) $obj_id)` inside the per-id loop) is never
 * reached.
 *
 * This covers the regression fix in installObject()'s error path: the
 * previously swallowed $result->error() message must now appear in the
 * failure message shown to the user.
 */
class ilObjLanguageFolderGUITest extends TestCase
{
    private function createGuiWithCollaborators(
        InstallLanguage $install_language,
        ilGlobalTemplateInterface $tpl,
        ilCtrl $ctrl,
        ilLanguage $lng
    ): ilObjLanguageFolderGUI {
        /** @var ilObjLanguageFolderGUI $gui */
        $gui = (new ReflectionClass(ilObjLanguageFolderGUI::class))->newInstanceWithoutConstructor();

        $this->setProperty($gui, 'install_language', $install_language);
        $this->setProperty($gui, 'current_user_id', 6);
        $this->setProperty($gui, 'tpl', $tpl);
        $this->setProperty($gui, 'ctrl', $ctrl);
        $this->setProperty($gui, 'lng', $lng);

        return $gui;
    }

    private function setProperty(object $object, string $property_name, mixed $value): void
    {
        $property = new ReflectionProperty($object, $property_name);
        $property->setValue($object, $value);
    }

    private function createLanguageMockReturningTopicAsIs(): ilLanguage&\PHPUnit\Framework\MockObject\MockObject
    {
        $lng = $this->createMock(ilLanguage::class);
        $lng->method('txt')->willReturnArgument(0);

        return $lng;
    }

    public function testInstallObjectEmbedsTheThrowableMessageFromAnErrorResultIntoTheFailureMessage(): void
    {
        $exception_message = 'Invalid language files: xx, yy';
        $install_language = $this->createMock(InstallLanguage::class);
        $install_language->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException($exception_message))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with(
                'failure',
                $this->stringContains($exception_message),
                true
            );

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithCollaborators(
            $install_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        // The mode is irrelevant for this test - it only exercises the error
        // path, which is reached before $mode has any effect on behavior.
        $gui->installObject([], InstallLanguage::MODE_INSTALL);
    }

    public function testInstallObjectEmbedsAPlainStringErrorFromAnErrorResultIntoTheFailureMessage(): void
    {
        // ILIAS\Data\Result\Error also accepts a plain string (not just a
        // Throwable) - the "$error instanceof \Throwable ? ... : $error"
        // branch for that case must be covered too.
        $error_string = 'permission denied';
        $install_language = $this->createMock(InstallLanguage::class);
        $install_language->method('maybePerformAs')->willReturn(new ResultError($error_string));

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($error_string), true);

        $ctrl = $this->createMock(ilCtrl::class);

        $gui = $this->createGuiWithCollaborators(
            $install_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->installObject([], InstallLanguage::MODE_INSTALL_LOCAL);
    }

    public function testInstallObjectRedirectsToViewAfterAnErrorResultAndDoesNotContinueToTheSuccessPath(): void
    {
        $install_language = $this->createMock(InstallLanguage::class);
        $install_language->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException('boom'))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithCollaborators(
            $install_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->installObject([], InstallLanguage::MODE_INSTALL);
    }

    /**
     * $mode is a plain pass-through: whatever installObject() is called
     * with must end up verbatim under the 'mode' key of
     * maybePerformAs()'s parameter array, alongside the language keys
     * resolved from $ids.
     */
    public function testInstallObjectPassesModeThroughToMaybePerformAs(): void
    {
        $install_language = $this->createMock(InstallLanguage::class);
        $install_language->expects($this->once())
            ->method('maybePerformAs')
            ->with(
                6,
                ['language_keys' => [], 'mode' => InstallLanguage::MODE_INSTALL_LOCAL]
            )
            ->willReturn(new ResultOk($this->emptyPerformResult()));

        $gui = $this->createGuiWithCollaborators(
            $install_language,
            $this->createMock(ilGlobalTemplateInterface::class),
            $this->createMock(ilCtrl::class),
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->installObject([], InstallLanguage::MODE_INSTALL_LOCAL);
    }

    /**
     * already_installed_language_keys and the new not_installed_language_keys
     * are both "nothing happened" buckets. setOnScreenMessage() only keeps
     * one message per type, so - exactly like the existing
     * success_messages combination pattern for installed/installed-with-local
     * - both must be combined into a single 'info' call instead of two
     * separate calls that would silently overwrite each other.
     */
    public function testInstallObjectCombinesAlreadyInstalledAndNotInstalledIntoOneInfoMessage(): void
    {
        $install_language = $this->createMock(InstallLanguage::class);
        $install_language->method('maybePerformAs')->willReturn(new ResultOk(
            [
                'installed_language_keys' => [],
                'installed_with_local_language_keys' => [],
                'already_installed_language_keys' => ['de'],
                'not_installed_language_keys' => ['fr'],
                'invalid_local_language_files' => [],
            ]
        ));

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with(
                'info',
                'languages_already_installed: meta_l_de<br />meta_l_fr language_not_installed',
                true
            );

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithCollaborators(
            $install_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->installObject([], InstallLanguage::MODE_INSTALL);
    }

    /**
     * @return array{installed_language_keys: list<string>, installed_with_local_language_keys: list<string>, already_installed_language_keys: list<string>, not_installed_language_keys: list<string>, invalid_local_language_files: list<string>}
     */
    private function emptyPerformResult(): array
    {
        return [
            'installed_language_keys' => [],
            'installed_with_local_language_keys' => [],
            'already_installed_language_keys' => [],
            'not_installed_language_keys' => [],
            'invalid_local_language_files' => [],
        ];
    }
}
