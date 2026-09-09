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
    // Regression coverage for the missing explicit ACL check in
    // installObject()/refreshSelectedObject().
    //
    // uninstallObject() and uninstallChangesObject() both call
    // $this->checkPermission('write') as their very first statement -
    // installObject() and refreshSelectedObject() do not (see class body).
    // The only remaining gate is the `table_action` dispatch in
    // executeCommand(), which calls checkPermission('write') itself
    // before invoking either method - but only when the method is
    // reached that way. Any other caller of these two *public* methods
    // (direct forwarding from another GUI, a future refactoring, a test,
    // ...) gets no ACL protection at all.
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
    // whether the method calls $this->checkPermission('write') at all -
    // exactly the call uninstallObject()/uninstallChangesObject() make
    // and installObject()/refreshSelectedObject() are missing. This is a
    // legitimate case for interaction verification (rule 16): the
    // presence of that call *is* the contract under test, not an
    // implementation detail. A partial mock of the GUI itself (stubbing
    // only checkPermission(), a protected method not otherwise
    // observable, while every other method keeps its real
    // implementation) makes that call directly assertable.
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
     * Control/sanity check for the technique above, not a regression test
     * in its own right: this pins that uninstallObject() *does* call
     * checkPermission('write') as its first statement, exactly as the
     * class body shows. If this failed, the mocking technique itself -
     * not the two methods under suspicion below - would be the problem.
     */
    public function testUninstallObjectChecksWritePermissionBeforeActing(): void
    {
        $gui = $this->createGuiWithMockedCheckPermission();
        $gui->expects($this->once())->method('checkPermission')->with('write');

        $gui->uninstallObject([]);
    }

    /**
     * Regression test for the suspected bug: installObject() must check
     * 'write' permission before doing any installation work - exactly
     * like its sibling uninstallObject() does, and like installObject()
     * itself used to before this refactoring (see
     * git show ff41db61599994e3f56141cbb58aed3116b68d8a - it had an
     * explicit $this->checkPermission("write"); as its first statement).
     * Currently, this method relies entirely on the caller (specifically,
     * executeCommand()'s own checkPermission('write') before dispatching
     * a `table_action`) - a caller-side check the method itself no longer
     * enforces. This test currently FAILS, confirming the bug: it invokes
     * install_language->maybePerformAs() without ever calling
     * checkPermission() at all.
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
     * Regression test for the suspected bug, refreshSelectedObject()
     * variant: same reasoning as
     * testInstallObjectMustCheckWritePermissionBeforeActing() above. This
     * test currently FAILS, confirming the bug.
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
}
