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
use ILIAS\Language\Activities\UpdateLanguage;
use ILIAS\Language\Activities\UninstallLanguage;
use ILIAS\Language\Activities\RemoveLocalLanguageChanges;
use ILIAS\Language\Activities\SetLanguageDetectionEnabled;
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

    // -----------------------------------------------------------------
    // Regression coverage for the explicit ACL check in installObject(),
    // refreshSelectedObject(), uninstallObject() and
    // uninstallChangesObject().
    //
    // All four write-command methods on this class -  installObject(),
    // uninstallObject(), uninstallChangesObject() and
    // refreshSelectedObject() - call $this->checkPermission('write') as
    // their very first statement (verified against the class body; see
    // also commit 4c98c957d36, "re-introduce explicit permission checks").
    // This is a defense-in-depth check: all four additionally delegate to
    // their respective Activity's isAllowedToPerform() (via
    // maybePerformAs()) - InstallLanguage, UninstallLanguage, UpdateLanguage
    // and RemoveLocalLanguageChanges respectively - so the permission is
    // enforced twice: once explicitly here, once inside the Activity (plus
    // the `table_action` dispatch in executeCommand(), which calls
    // checkPermission('write') itself before invoking any of them when
    // reached that way - but any other caller of one of these *public*
    // methods gets no ACL protection beyond the method's own explicit
    // check and its Activity's isAllowedToPerform()).
    //
    // checkPermissionBool() (which checkPermission() delegates to) simply
    // returns false whenever $this->object is not an object - which it
    // never is on a reflection-constructed instance - so checkPermission()
    // takes its early, silent `return;` branch and never actually reaches
    // $this->access, $this->tpl or $this->ctrl. That makes it impossible
    // to fake a "permission denied" *outcome* through $access in this kind
    // of lightweight unit test: the method no-ops the same way regardless
    // of whether real RBAC would grant or deny 'write', and - even if it
    // didn't - checkPermission() itself never throws or exits, it only
    // records a flash message and calls $ctrl->redirectToURL(), which is a
    // real redirect+exit only at the HTTP layer; against a mocked $ctrl it
    // is just another recorded call, so it would not actually stop
    // execution here either. A real integration test (with a genuine
    // ilAccessHandler backed by RBAC/DB and a request that never reaches
    // executeCommand()'s own gate) would be needed to observe an *outcome*
    // difference (data changed vs. not changed) for a denied user.
    //
    // What a unit test *can* pin down directly is the contract itself:
    // that the method calls $this->checkPermission('write') exactly once,
    // as its first statement, before doing any work. This is a legitimate
    // case for interaction verification (rule 16): the presence of that
    // call *is* the contract under test, not an implementation detail. A
    // partial mock of the GUI itself (stubbing only checkPermission(), a
    // protected method not otherwise observable, while every other method
    // keeps its real implementation) makes that call directly assertable.
    // -----------------------------------------------------------------

    /**
     * @return ilObjLanguageFolderGUI&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createGuiWithMockedCheckPermission(): ilObjLanguageFolderGUI
    {
        /** @var ilObjLanguageFolderGUI&\PHPUnit\Framework\MockObject\MockObject $gui */
        $gui = $this->getMockBuilder(ilObjLanguageFolderGUI::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkPermission'])
            ->getMock();

        $this->setProperty($gui, 'lng', $this->createLanguageMockReturningTopicAsIs());
        $this->setProperty($gui, 'tpl', $this->createMock(ilGlobalTemplateInterface::class));
        $this->setProperty($gui, 'ctrl', $this->createMock(ilCtrl::class));
        // 'current_user_id' is a private readonly property declared directly
        // on ilObjLanguageFolderGUI (not an ancestor). On a PHPUnit mock
        // subclass, plain `new ReflectionProperty($gui, name)` cannot locate
        // a *readonly* property declared on the leaf class this way -
        // unlike the plain (non-readonly) $tpl/$ctrl/$lng above, which are
        // declared on the ilObjectGUI ancestor and are found fine either
        // way - so it must be looked up via the exact declaring class
        // instead, and set on the mock instance from there.
        $this->setReadonlyPropertyDeclaredOnGuiClass($gui, 'current_user_id', 6);

        return $gui;
    }

    private function setReadonlyPropertyDeclaredOnGuiClass(
        ilObjLanguageFolderGUI $gui,
        string $property_name,
        mixed $value
    ): void {
        (new ReflectionClass(ilObjLanguageFolderGUI::class))
            ->getProperty($property_name)
            ->setValue($gui, $value);
    }

    /**
     * uninstallObject() must call $this->checkPermission('write') exactly
     * once, as its first statement, before doing any uninstall work -
     * exactly like installObject()/refreshSelectedObject()/
     * uninstallChangesObject() do. In addition, UninstallLanguage enforces
     * the same 'write' permission itself via isAllowedToPerform()/
     * maybePerformAs() (see
     * UninstallLanguageTest::testPermissionDeniedBeforePerformNeverCallsObjectFactoryOrUninstall()
     * for that side of the contract) - this is a deliberate
     * defense-in-depth: the GUI-level check here does not replace the
     * Activity-level check, both must hold.
     */
    public function testUninstallObjectChecksWritePermissionBeforeActing(): void
    {
        $gui = $this->createGuiWithMockedCheckPermission();
        $gui->expects($this->once())->method('checkPermission')->with('write');

        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(
            new ResultOk($this->uninstallPerformResult([], [], [], []))
        );
        $this->setReadonlyPropertyDeclaredOnGuiClass($gui, 'uninstall_language', $uninstall_language);

        $gui->uninstallObject([]);
    }

    /**
     * installObject() must check 'write' permission before doing any
     * installation work - exactly like its siblings
     * uninstallObject()/refreshSelectedObject()/uninstallChangesObject()
     * do (see the class body: all four call $this->checkPermission('write')
     * as their first statement).
     */
    public function testInstallObjectMustCheckWritePermissionBeforeActing(): void
    {
        $gui = $this->createGuiWithMockedCheckPermission();
        $gui->expects($this->once())->method('checkPermission')->with('write');

        $install_language = $this->createMock(InstallLanguage::class);
        $install_language->method('maybePerformAs')->willReturn(new ResultOk($this->emptyPerformResult()));
        $this->setReadonlyPropertyDeclaredOnGuiClass($gui, 'install_language', $install_language);

        // Empty $ids, same reasoning as the other installObject() tests
        // above: avoids the unrelated `new ilObjLanguage(...)` legacy
        // coupling, which is irrelevant to whether the permission check
        // happens at all.
        $gui->installObject([], InstallLanguage::MODE_INSTALL);
    }

    /**
     * refreshSelectedObject() variant: same reasoning as
     * testInstallObjectMustCheckWritePermissionBeforeActing() above.
     */
    public function testRefreshSelectedObjectMustCheckWritePermissionBeforeActing(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $gui = $this->createGuiWithMockedCheckPermission();
        $gui->expects($this->once())->method('checkPermission')->with('write');

        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(new ResultOk($this->updatePerformResult([], [])));
        $this->setReadonlyPropertyDeclaredOnGuiClass($gui, 'update_language', $update_language);

        $gui->refreshSelectedObject([]);
    }

    /**
     * uninstallChangesObject() variant: same reasoning as
     * testInstallObjectMustCheckWritePermissionBeforeActing() above. Also
     * mirrors the defense-in-depth reasoning of
     * testUninstallObjectChecksWritePermissionBeforeActing(): in addition
     * to this explicit GUI-level check, RemoveLocalLanguageChanges enforces
     * the same 'write' permission itself via isAllowedToPerform()/
     * maybePerformAs() (see
     * RemoveLocalLanguageChangesTest::testPermissionDeniedBeforePerformNeverCallsObjectFactoryOrRemoveLocalChanges()
     * for that side of the contract) - the GUI-level check here does not
     * replace the Activity-level check, both must hold.
     */
    public function testUninstallChangesObjectMustCheckWritePermissionBeforeActing(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $gui = $this->createGuiWithMockedCheckPermission();
        $gui->expects($this->once())->method('checkPermission')->with('write');

        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(
            new ResultOk($this->removeLocalChangesPerformResult([], [], []))
        );
        $this->setReadonlyPropertyDeclaredOnGuiClass(
            $gui,
            'remove_local_language_changes',
            $remove_local_language_changes
        );

        $gui->uninstallChangesObject([]);
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

    // -----------------------------------------------------------------
    // refreshSelectedObject()
    //
    // Same reflection-based construction technique as installObject()
    // above, with $ids always [] for the same reason: the only other
    // piece of legacy coupling in the method (`new ilObjLanguage((int)
    // $obj_id)` inside the per-id loop that builds $language_keys) must
    // never be reached in a unit test.
    // -----------------------------------------------------------------

    private function createGuiWithUpdateLanguageCollaborators(
        UpdateLanguage $update_language,
        ilGlobalTemplateInterface $tpl,
        ilCtrl $ctrl,
        ilLanguage $lng
    ): ilObjLanguageFolderGUI {
        /** @var ilObjLanguageFolderGUI $gui */
        $gui = (new ReflectionClass(ilObjLanguageFolderGUI::class))->newInstanceWithoutConstructor();

        $this->setProperty($gui, 'update_language', $update_language);
        $this->setProperty($gui, 'current_user_id', 6);
        $this->setProperty($gui, 'tpl', $tpl);
        $this->setProperty($gui, 'ctrl', $ctrl);
        $this->setProperty($gui, 'lng', $lng);

        return $gui;
    }

    /**
     * Unlike installObject(), refreshSelectedObject() calls the legacy
     * static ilObjLanguage::refreshPlugins() unconditionally on every
     * success path (even when nothing was updated) - and that static
     * method reaches into `global $DIC["component.repository"]`. An
     * empty plugin iterator here means the foreach in refreshPlugins()
     * never enters its body, which is what keeps this a unit test:
     * anything beyond "is there a plugin" (ilPluginLanguage's actual
     * update logic) reads real files and queries the database - legacy
     * internals that are unrelated to and unchanged by this refactoring,
     * so this test suite deliberately does not attempt to verify which
     * exact keys refreshPlugins() receives from
     * $value['updated_language_keys']. That one-line pass-through was
     * verified by inspection instead.
     */
    private function stubComponentRepositoryWithNoPlugins(): void
    {
        $repository = $this->createMock(ilComponentRepository::class);
        $repository->method('getPlugins')->willReturn(new ArrayIterator([]));

        $GLOBALS['DIC'] = new \ILIAS\DI\Container();
        $GLOBALS['DIC']['component.repository'] = $repository;
    }

    /**
     * @return array{updated_language_keys: list<string>, not_installed_language_keys: list<string>}
     */
    private function updatePerformResult(array $updated, array $not_installed): array
    {
        return [
            'updated_language_keys' => $updated,
            'not_installed_language_keys' => $not_installed,
        ];
    }

    public function testRefreshSelectedObjectEmbedsTheThrowableMessageFromAnErrorResultIntoTheFailureMessage(): void
    {
        $exception_message = 'Invalid language files: xx, yy';
        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException($exception_message))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($exception_message), true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        // The error path is reached before ilObjLanguage::refreshPlugins()
        // would ever be called, so no $DIC stub is needed here.
        $gui->refreshSelectedObject([]);
    }

    public function testRefreshSelectedObjectEmbedsAPlainStringErrorFromAnErrorResultIntoTheFailureMessage(): void
    {
        // ILIAS\Data\Result\Error also accepts a plain string (not just a
        // Throwable) - the "$error instanceof \Throwable ? ... : $error"
        // branch for that case must be covered too.
        $error_string = 'permission denied';
        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(new ResultError($error_string));

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($error_string), true);

        $ctrl = $this->createMock(ilCtrl::class);

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->refreshSelectedObject([]);
    }

    public function testRefreshSelectedObjectRedirectsToViewAfterAnErrorResultAndDoesNotContinueToTheSuccessPath(): void
    {
        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException('boom'))
        );

        // If the `return` after the redirect were ever dropped, execution
        // would fall through to `$result->value()` - which throws on an
        // Error result (see ILIAS\Data\Result\Error::value()) - so this
        // would surface as a test error rather than silently passing.
        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())->method('setOnScreenMessage');

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->refreshSelectedObject([]);
    }

    /**
     * UpdateLanguage::perform() has no "mode" concept (unlike
     * InstallLanguage) - only 'language_keys' may ever be sent.
     */
    public function testRefreshSelectedObjectPassesOnlyLanguageKeysWithoutAModeKeyToMaybePerformAs(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->expects($this->once())
            ->method('maybePerformAs')
            ->with(6, ['language_keys' => []])
            ->willReturn(new ResultOk($this->updatePerformResult([], [])));

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $this->createMock(ilGlobalTemplateInterface::class),
            $this->createMock(ilCtrl::class),
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->refreshSelectedObject([]);
    }

    public function testRefreshSelectedObjectSetsSuccessMessageWhenOnlyUpdatedLanguageKeysIsNonEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(
            new ResultOk($this->updatePerformResult(['de'], []))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('success', 'selected_languages_updated meta_l_de', true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->refreshSelectedObject([]);
    }

    public function testRefreshSelectedObjectSetsInfoMessageWhenOnlyNotInstalledLanguageKeysIsNonEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(
            new ResultOk($this->updatePerformResult([], ['fr']))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('info', 'meta_l_fr language_not_installed', true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->refreshSelectedObject([]);
    }

    /**
     * Unlike installObject()'s combined success message (two "nothing
     * happened" / two "something happened" buckets sharing one message
     * type, and therefore explicitly combined into one call to avoid a
     * silent overwrite), 'success' and 'info' are different message
     * types here - so both setOnScreenMessage() calls must actually
     * happen, as two separate calls, neither one clobbering the other.
     */
    public function testRefreshSelectedObjectSetsBothSuccessAndInfoMessagesWhenBothBucketsAreNonEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(
            new ResultOk($this->updatePerformResult(['de'], ['fr']))
        );

        $captured_calls = [];
        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->exactly(2))
            ->method('setOnScreenMessage')
            ->willReturnCallback(function (string $type, string $message, bool $keep) use (&$captured_calls): void {
                $captured_calls[] = [$type, $message];
            });

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->refreshSelectedObject([]);

        self::assertCount(2, $captured_calls);
        self::assertSame(['success', 'selected_languages_updated meta_l_de'], $captured_calls[0]);
        self::assertSame(['info', 'meta_l_fr language_not_installed'], $captured_calls[1]);
    }

    /**
     * Boundary: when nothing was requested (or every requested language
     * was already covered by neither bucket - which cannot actually
     * happen per UpdateLanguage::perform()'s contract, but the two
     * `!== []` checks must still degrade correctly for the case that
     * genuinely does occur, an empty $ids request), no message at all
     * must appear - not even an empty one.
     */
    public function testRefreshSelectedObjectSetsNoMessageWhenBothBucketsAreEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $update_language = $this->createMock(UpdateLanguage::class);
        $update_language->method('maybePerformAs')->willReturn(
            new ResultOk($this->updatePerformResult([], []))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->never())->method('setOnScreenMessage');

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithUpdateLanguageCollaborators(
            $update_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->refreshSelectedObject([]);
    }

    // -----------------------------------------------------------------
    // uninstallObject()
    //
    // Same reflection-based construction technique as installObject()/
    // refreshSelectedObject() above, with $ids always [] for the same
    // reason: the only other piece of legacy coupling in the method
    // (`ilObject::_lookupTitle((int) $obj_id)` inside the per-id loop
    // that builds $language_keys) must never be reached in a unit test.
    // -----------------------------------------------------------------

    private function createGuiWithUninstallLanguageCollaborators(
        UninstallLanguage $uninstall_language,
        ilGlobalTemplateInterface $tpl,
        ilCtrl $ctrl,
        ilLanguage $lng
    ): ilObjLanguageFolderGUI {
        /** @var ilObjLanguageFolderGUI $gui */
        $gui = (new ReflectionClass(ilObjLanguageFolderGUI::class))->newInstanceWithoutConstructor();

        $this->setProperty($gui, 'uninstall_language', $uninstall_language);
        $this->setProperty($gui, 'current_user_id', 6);
        $this->setProperty($gui, 'tpl', $tpl);
        $this->setProperty($gui, 'ctrl', $ctrl);
        $this->setProperty($gui, 'lng', $lng);

        return $gui;
    }

    /**
     * @return array{uninstalled_language_keys: list<string>, system_language_keys: list<string>, user_language_keys: list<string>, not_installed_language_keys: list<string>}
     */
    private function uninstallPerformResult(
        array $uninstalled,
        array $system,
        array $user,
        array $not_installed
    ): array {
        return [
            'uninstalled_language_keys' => $uninstalled,
            'system_language_keys' => $system,
            'user_language_keys' => $user,
            'not_installed_language_keys' => $not_installed,
        ];
    }

    public function testUninstallObjectEmbedsTheThrowableMessageFromAnErrorResultIntoTheFailureMessage(): void
    {
        $exception_message = 'boom';
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException($exception_message))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($exception_message), true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);
    }

    public function testUninstallObjectEmbedsAPlainStringErrorFromAnErrorResultIntoTheFailureMessage(): void
    {
        // ILIAS\Data\Result\Error also accepts a plain string (not just a
        // Throwable) - the "$error instanceof \Throwable ? ... : $error"
        // branch for that case must be covered too.
        $error_string = 'permission denied';
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(new ResultError($error_string));

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($error_string), true);

        $ctrl = $this->createMock(ilCtrl::class);

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);
    }

    public function testUninstallObjectRedirectsToViewAfterAnErrorResultAndDoesNotContinueToTheSuccessPath(): void
    {
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException('boom'))
        );

        // If the `return` after the redirect were ever dropped, execution
        // would fall through to `$result->value()` - which throws on an
        // Error result (see ILIAS\Data\Result\Error::value()) - so this
        // would surface as a test error rather than silently passing.
        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())->method('setOnScreenMessage');

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);
    }

    /**
     * $ids is resolved to language keys via
     * ilObject::_lookupTitle((int) $obj_id) - untestable in isolation
     * without a real database, so - exactly like the existing
     * install/update tests - this is only exercised with an empty $ids
     * array, which is enough to pin that maybePerformAs() receives an
     * (empty) 'language_keys' array and no other key.
     */
    public function testUninstallObjectPassesOnlyLanguageKeysWithoutAModeKeyToMaybePerformAs(): void
    {
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->expects($this->once())
            ->method('maybePerformAs')
            ->with(6, ['language_keys' => []])
            ->willReturn(new ResultOk($this->uninstallPerformResult([], [], [], [])));

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $this->createMock(ilGlobalTemplateInterface::class),
            $this->createMock(ilCtrl::class),
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);
    }

    public function testUninstallObjectSetsSuccessMessageWhenOnlyUninstalledLanguageKeysIsNonEmpty(): void
    {
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(
            new ResultOk($this->uninstallPerformResult(['de'], [], [], []))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('success', 'meta_l_de uninstalled', true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);
    }

    /**
     * system_language_keys, user_language_keys and not_installed_language_keys
     * are all "nothing changed for this language, here is why" outcomes.
     * setOnScreenMessage() only keeps one message per type, so - exactly
     * like installObject()'s combined info message - all three buckets
     * must be combined into a single 'info' call instead of three
     * separate calls that would silently overwrite each other.
     */
    public function testUninstallObjectCombinesSystemUserAndNotInstalledIntoOneInfoMessage(): void
    {
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(new ResultOk(
            $this->uninstallPerformResult([], ['de'], ['en'], ['fr'])
        ));

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with(
                'info',
                'cannot_uninstall_systemlanguage: meta_l_de<br />'
                    . 'cannot_uninstall_language_in_use: meta_l_en<br />'
                    . 'languages_already_uninstalled: meta_l_fr',
                true
            );

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);
    }

    /**
     * A single request can mix a genuinely uninstalled language with all
     * three "nothing changed" buckets at once - the success message and
     * the combined info message must both be set, as two separate calls
     * (different message types), neither one clobbering the other.
     */
    public function testUninstallObjectSetsBothSuccessAndInfoMessagesWhenBothCategoriesAreNonEmpty(): void
    {
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(new ResultOk(
            $this->uninstallPerformResult(['de'], ['en'], [], [])
        ));

        $captured_calls = [];
        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->exactly(2))
            ->method('setOnScreenMessage')
            ->willReturnCallback(function (string $type, string $message, bool $keep) use (&$captured_calls): void {
                $captured_calls[] = [$type, $message];
            });

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);

        self::assertCount(2, $captured_calls);
        self::assertSame(['success', 'meta_l_de uninstalled'], $captured_calls[0]);
        self::assertSame(['info', 'cannot_uninstall_systemlanguage: meta_l_en'], $captured_calls[1]);
    }

    /**
     * Boundary: when every bucket is empty (e.g. an empty $ids request),
     * no message at all must appear - not even an empty one.
     */
    public function testUninstallObjectSetsNoMessageWhenAllBucketsAreEmpty(): void
    {
        $uninstall_language = $this->createMock(UninstallLanguage::class);
        $uninstall_language->method('maybePerformAs')->willReturn(
            new ResultOk($this->uninstallPerformResult([], [], [], []))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->never())->method('setOnScreenMessage');

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithUninstallLanguageCollaborators(
            $uninstall_language,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallObject([]);
    }

    // -----------------------------------------------------------------
    // uninstallChangesObject()
    //
    // Same reflection-based construction technique as uninstallObject()
    // above, with $ids always [] for the same reason: the only other piece
    // of legacy coupling in the method (`ilObject::_lookupTitle((int)
    // $obj_id)` inside the per-id loop that builds $language_keys) must
    // never be reached in a unit test.
    //
    // Unlike uninstallObject(), uninstallChangesObject() calls the legacy
    // static ilObjLanguage::refreshPlugins() unconditionally on every
    // success path (even when nothing was changed) - exactly like
    // refreshSelectedObject() does (see stubComponentRepositoryWithNoPlugins()'s
    // docblock above for why an empty plugin iterator is what keeps this a
    // unit test), so every success-path test below needs that same stub.
    // -----------------------------------------------------------------

    private function createGuiWithRemoveLocalLanguageChangesCollaborators(
        RemoveLocalLanguageChanges $remove_local_language_changes,
        ilGlobalTemplateInterface $tpl,
        ilCtrl $ctrl,
        ilLanguage $lng
    ): ilObjLanguageFolderGUI {
        /** @var ilObjLanguageFolderGUI $gui */
        $gui = (new ReflectionClass(ilObjLanguageFolderGUI::class))->newInstanceWithoutConstructor();

        $this->setProperty($gui, 'remove_local_language_changes', $remove_local_language_changes);
        $this->setProperty($gui, 'current_user_id', 6);
        $this->setProperty($gui, 'tpl', $tpl);
        $this->setProperty($gui, 'ctrl', $ctrl);
        $this->setProperty($gui, 'lng', $lng);

        return $gui;
    }

    /**
     * @return array{removed_local_changes_language_keys: list<string>, invalid_language_file_keys: list<string>, not_installed_language_keys: list<string>}
     */
    private function removeLocalChangesPerformResult(
        array $removed_local_changes,
        array $invalid_language_file,
        array $not_installed
    ): array {
        return [
            'removed_local_changes_language_keys' => $removed_local_changes,
            'invalid_language_file_keys' => $invalid_language_file,
            'not_installed_language_keys' => $not_installed,
        ];
    }

    public function testUninstallChangesObjectEmbedsTheThrowableMessageFromAnErrorResultIntoTheFailureMessage(): void
    {
        // The error path is reached before ilObjLanguage::refreshPlugins()
        // would ever be called, so no $DIC stub is needed here.
        $exception_message = 'boom';
        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException($exception_message))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($exception_message), true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    public function testUninstallChangesObjectEmbedsAPlainStringErrorFromAnErrorResultIntoTheFailureMessage(): void
    {
        // ILIAS\Data\Result\Error also accepts a plain string (not just a
        // Throwable) - the "$error instanceof \Throwable ? ... : $error"
        // branch for that case must be covered too.
        $error_string = 'permission denied';
        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(new ResultError($error_string));

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($error_string), true);

        $ctrl = $this->createMock(ilCtrl::class);

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    public function testUninstallChangesObjectRedirectsToViewAfterAnErrorResultAndDoesNotContinueToTheSuccessPath(): void
    {
        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException('boom'))
        );

        // If the `return` after the redirect were ever dropped, execution
        // would fall through to `$result->value()` - which throws on an
        // Error result (see ILIAS\Data\Result\Error::value()) - and, even
        // past that, to the unconditional ilObjLanguage::refreshPlugins()
        // call, which reaches into `global $DIC["component.repository"]`
        // and is not stubbed here - so this would surface as a test error
        // rather than silently passing.
        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())->method('setOnScreenMessage');

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    /**
     * $ids is resolved to language keys via
     * ilObject::_lookupTitle((int) $obj_id) - untestable in isolation
     * without a real database, so - exactly like the existing
     * uninstallObject() test - this is only exercised with an empty $ids
     * array, which is enough to pin that maybePerformAs() receives an
     * (empty) 'language_keys' array and no other key.
     */
    public function testUninstallChangesObjectPassesOnlyLanguageKeysWithoutAModeKeyToMaybePerformAs(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->expects($this->once())
            ->method('maybePerformAs')
            ->with(6, ['language_keys' => []])
            ->willReturn(new ResultOk($this->removeLocalChangesPerformResult([], [], [])));

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $this->createMock(ilGlobalTemplateInterface::class),
            $this->createMock(ilCtrl::class),
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    public function testUninstallChangesObjectSetsSuccessMessageWhenOnlyRemovedLocalChangesLanguageKeysIsNonEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(
            new ResultOk($this->removeLocalChangesPerformResult(['de'], [], []))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('success', 'selected_languages_updated<br />meta_l_de', true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    public function testUninstallChangesObjectSetsFailureMessageWhenOnlyInvalidLanguageFileKeysIsNonEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(
            new ResultOk($this->removeLocalChangesPerformResult([], ['fr'], []))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', 'meta_l_fr: file_not_valid', true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    public function testUninstallChangesObjectSetsInfoMessageWhenOnlyNotInstalledLanguageKeysIsNonEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(
            new ResultOk($this->removeLocalChangesPerformResult([], [], ['it']))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('info', 'meta_l_it language_not_installed', true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    /**
     * Unlike uninstallObject()'s combined info message (three "nothing
     * happened" buckets sharing one message type, therefore explicitly
     * combined into one call to avoid a silent overwrite), all three
     * buckets here are different message types (success/failure/info) - so
     * all three setOnScreenMessage() calls must actually happen, as three
     * separate calls, none clobbering another.
     */
    public function testUninstallChangesObjectSetsAllThreeMessagesWhenAllBucketsAreNonEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(new ResultOk(
            $this->removeLocalChangesPerformResult(['de'], ['fr'], ['it'])
        ));

        $captured_calls = [];
        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->exactly(3))
            ->method('setOnScreenMessage')
            ->willReturnCallback(function (string $type, string $message, bool $keep) use (&$captured_calls): void {
                $captured_calls[] = [$type, $message];
            });

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect');

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);

        self::assertCount(3, $captured_calls);
        self::assertSame(['success', 'selected_languages_updated<br />meta_l_de'], $captured_calls[0]);
        self::assertSame(['failure', 'meta_l_fr: file_not_valid'], $captured_calls[1]);
        self::assertSame(['info', 'meta_l_it language_not_installed'], $captured_calls[2]);
    }

    /**
     * Boundary: when every bucket is empty (e.g. an empty $ids request),
     * no message at all must appear - not even an empty one. This is also
     * the deliberate behavior change against the pre-Activity legacy code
     * (see uninstallChangesObject()'s docblock): the old code always showed
     * a "selected_languages_updated" message, even when nothing changed.
     */
    public function testUninstallChangesObjectSetsNoMessageWhenAllBucketsAreEmpty(): void
    {
        $this->stubComponentRepositoryWithNoPlugins();

        $remove_local_language_changes = $this->createMock(RemoveLocalLanguageChanges::class);
        $remove_local_language_changes->method('maybePerformAs')->willReturn(
            new ResultOk($this->removeLocalChangesPerformResult([], [], []))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->never())->method('setOnScreenMessage');

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithRemoveLocalLanguageChangesCollaborators(
            $remove_local_language_changes,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        $gui->uninstallChangesObject([]);
    }

    // -----------------------------------------------------------------
    // enableLanguageDetectionObject() / disableLanguageDetectionObject()
    // (both backed by setLanguageDetectionEnabledObject())
    //
    // Unlike installObject()/uninstallObject()/refreshSelectedObject()/
    // uninstallChangesObject(), neither of these two methods calls
    // $this->checkPermission('write') itself - this is unchanged legacy
    // behaviour (see SetLanguageDetectionEnabled's class docblock and the
    // task's security note): before the extraction, only the toggle
    // button's visibility in viewObject() was write-gated, the request
    // itself was not. The extraction deliberately does not retrofit a
    // GUI-level checkPermission() call here (that would be an unrequested,
    // additional behavioural change on top of the intended one) - the
    // *only* enforcement point is now SetLanguageDetectionEnabled's own
    // isAllowedToPerform(), reached through maybePerformAs(). This is
    // asserted directly below, in contrast to the defense-in-depth tests
    // above for the other four write methods.
    //
    // Both action methods are `protected` (dispatched only via ilCtrl),
    // hence invoked here through reflection.
    // -----------------------------------------------------------------

    private function invokeProtectedMethod(object $object, string $method_name, array $args = []): mixed
    {
        // ReflectionMethod::setAccessible() has had no effect (and has been
        // deprecated) since PHP 8.1 - invoke() already bypasses visibility
        // on its own since then.
        return (new ReflectionMethod($object, $method_name))->invoke($object, ...$args);
    }

    private function createGuiWithSetLanguageDetectionEnabledCollaborators(
        SetLanguageDetectionEnabled $set_language_detection_enabled,
        ilGlobalTemplateInterface $tpl,
        ilCtrl $ctrl,
        ilLanguage $lng
    ): ilObjLanguageFolderGUI {
        /** @var ilObjLanguageFolderGUI $gui */
        $gui = (new ReflectionClass(ilObjLanguageFolderGUI::class))->newInstanceWithoutConstructor();

        $this->setProperty($gui, 'set_language_detection_enabled', $set_language_detection_enabled);
        $this->setProperty($gui, 'current_user_id', 6);
        $this->setProperty($gui, 'tpl', $tpl);
        $this->setProperty($gui, 'ctrl', $ctrl);
        $this->setProperty($gui, 'lng', $lng);

        return $gui;
    }

    public function testEnableLanguageDetectionObjectCallsMaybePerformAsWithEnabledTrue(): void
    {
        $set_language_detection_enabled = $this->createMock(SetLanguageDetectionEnabled::class);
        $set_language_detection_enabled->expects($this->once())
            ->method('maybePerformAs')
            ->with(6, ['enabled' => true])
            ->willReturn(new ResultError(new \RuntimeException('boom')));

        $gui = $this->createGuiWithSetLanguageDetectionEnabledCollaborators(
            $set_language_detection_enabled,
            $this->createMock(ilGlobalTemplateInterface::class),
            $this->createMock(ilCtrl::class),
            $this->createLanguageMockReturningTopicAsIs()
        );

        $this->invokeProtectedMethod($gui, 'enableLanguageDetectionObject');
    }

    public function testDisableLanguageDetectionObjectCallsMaybePerformAsWithEnabledFalse(): void
    {
        $set_language_detection_enabled = $this->createMock(SetLanguageDetectionEnabled::class);
        $set_language_detection_enabled->expects($this->once())
            ->method('maybePerformAs')
            ->with(6, ['enabled' => false])
            ->willReturn(new ResultError(new \RuntimeException('boom')));

        $gui = $this->createGuiWithSetLanguageDetectionEnabledCollaborators(
            $set_language_detection_enabled,
            $this->createMock(ilGlobalTemplateInterface::class),
            $this->createMock(ilCtrl::class),
            $this->createLanguageMockReturningTopicAsIs()
        );

        $this->invokeProtectedMethod($gui, 'disableLanguageDetectionObject');
    }

    public function testSetLanguageDetectionEnabledObjectEmbedsTheThrowableMessageAndRedirectsOnError(): void
    {
        $exception_message = 'no write permission';
        $set_language_detection_enabled = $this->createMock(SetLanguageDetectionEnabled::class);
        $set_language_detection_enabled->method('maybePerformAs')->willReturn(
            new ResultError(new \RuntimeException($exception_message))
        );

        $tpl = $this->createMock(ilGlobalTemplateInterface::class);
        $tpl->expects($this->once())
            ->method('setOnScreenMessage')
            ->with('failure', $this->stringContains($exception_message), true);

        $ctrl = $this->createMock(ilCtrl::class);
        $ctrl->expects($this->once())->method('redirect')->with(
            $this->isInstanceOf(ilObjLanguageFolderGUI::class),
            'view'
        );

        $gui = $this->createGuiWithSetLanguageDetectionEnabledCollaborators(
            $set_language_detection_enabled,
            $tpl,
            $ctrl,
            $this->createLanguageMockReturningTopicAsIs()
        );

        // If the `return` after the redirect were ever dropped, execution
        // would fall through to the success path, which calls
        // $this->viewObject() - a method that reaches deep into
        // un-stubbed legacy collaborators ($this->languageFolderTable,
        // $this->ui_factory, $this->toolbar, ...) on this
        // reflection-constructed instance and would surface as a fatal
        // error/TypeError rather than silently passing.
        $this->invokeProtectedMethod($gui, 'enableLanguageDetectionObject');
    }

    /**
     * Regression test documenting the deliberate asymmetry described above:
     * unlike installObject()/uninstallObject()/refreshSelectedObject()/
     * uninstallChangesObject() (all four call $this->checkPermission('write')
     * as their first statement, see the tests earlier in this class),
     * enableLanguageDetectionObject()/disableLanguageDetectionObject() must
     * NOT call checkPermission() themselves - this was true before the
     * extraction and remains true after it. The only enforcement is now
     * SetLanguageDetectionEnabled::isAllowedToPerform() via maybePerformAs()
     * (see SetLanguageDetectionEnabledTest::
     * testMaybePerformAsWithDeniedPermissionReturnsErrorAndNeverWritesTheSetting()
     * for that side of the contract).
     */
    public function testSetLanguageDetectionEnabledObjectNeverCallsCheckPermission(): void
    {
        /** @var ilObjLanguageFolderGUI&\PHPUnit\Framework\MockObject\MockObject $gui */
        $gui = $this->getMockBuilder(ilObjLanguageFolderGUI::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkPermission'])
            ->getMock();
        $gui->expects($this->never())->method('checkPermission');

        $this->setProperty($gui, 'lng', $this->createLanguageMockReturningTopicAsIs());
        $this->setProperty($gui, 'tpl', $this->createMock(ilGlobalTemplateInterface::class));
        $this->setProperty($gui, 'ctrl', $this->createMock(ilCtrl::class));
        $this->setReadonlyPropertyDeclaredOnGuiClass($gui, 'current_user_id', 6);

        $set_language_detection_enabled = $this->createMock(SetLanguageDetectionEnabled::class);
        // Permission denial is simulated at the Activity level (an Error
        // result) so the success path (which would call $this->viewObject(),
        // unreachable here) is never taken - this test is only about
        // checkPermission() never being invoked, not about the outcome of
        // the permission check itself.
        $set_language_detection_enabled->method('maybePerformAs')->willReturn(
            new ResultError('no write permission')
        );
        $this->setReadonlyPropertyDeclaredOnGuiClass(
            $gui,
            'set_language_detection_enabled',
            $set_language_detection_enabled
        );

        $this->invokeProtectedMethod($gui, 'enableLanguageDetectionObject');
        $this->invokeProtectedMethod($gui, 'disableLanguageDetectionObject');
    }
}
