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

namespace ILIAS;

use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\Activities\InstallLanguage;
use ILIAS\Language\Activities\UpdateLanguage;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Language\Setup\InstalledLanguageDatabaseRepository;
use ILIAS\Language\Setup\LanguageInstallationManager;

class Language implements Component\Component
{
    public function init(
        array | \ArrayAccess &$define,
        array | \ArrayAccess &$implement,
        array | \ArrayAccess &$use,
        array | \ArrayAccess &$contribute,
        array | \ArrayAccess &$seek,
        array | \ArrayAccess &$provide,
        array | \ArrayAccess &$pull,
        array | \ArrayAccess &$internal,
    ): void {
        $define[] = \ILIAS\Language\Language::class;

        // Shared by every $internal service below that needs the Setup/
        // installation database - deduplicated here instead of being
        // reconstructed identically in each closure.
        $resolve_db = static fn(): \ilDBInterface => $GLOBALS['ilDB'] ?? $GLOBALS['DIC']->database();
        $ilias_root = (string) realpath(__DIR__ . '/../../../');

        // --- $internal: wiring local to this component -----------------
        // Ordered so each entry's dependencies are declared above it.

        $internal[LanguageFileDirectoryManager::class] = static fn() =>
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                ...$seek[LanguageFileDirectory::class]
            );

        // Read (InstalledLanguageRepository) and write (LanguageInstallationManager)
        // access to the language installation domain, extracted from
        // ilSetupLanguage per docs/development/repository-pattern.md - see that
        // class' docblock. ilSetupLanguage itself keeps delegating to both and
        // remains the \ILIAS\Language\Language implementation used during Setup
        // (see $implement[...] below); these two are for consumers that only
        // need install/retrieval behaviour, not txt() - see $provide[...] below
        // and components/ILIAS/Language/README.md.
        $internal[InstalledLanguageDatabaseRepository::class] = static fn() =>
            new InstalledLanguageDatabaseRepository(
                $resolve_db,
                $internal[LanguageFileDirectoryManager::class],
                $ilias_root
            );

        $internal[LanguageInstallationManager::class] = static fn() =>
            new LanguageInstallationManager(
                $resolve_db,
                $internal[LanguageFileDirectoryManager::class],
                $ilias_root,
                $internal[InstalledLanguageDatabaseRepository::class]
            );

        $internal[\ilSetupLanguage::class] = static fn() =>
            new \ilSetupLanguage(
                "en",
                $internal[LanguageFileDirectoryManager::class]
            );

        $internal[InstallLanguage::class] = static fn() =>
            new InstallLanguage(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\UI\Factory::class],
                $use[\ILIAS\Language\Language::class],
                static fn(): \ilRbacSystem => $GLOBALS['DIC']->rbac()->system(),
                // The Activity needs no database of its own: every database
                // access in perform() goes through ilSetupLanguage, which
                // resolves it itself.
                $internal[\ilSetupLanguage::class],
                static fn(): int => \ilObjLanguageAccess::_lookupLangFolderRefId()
            );

        $internal[UpdateLanguage::class] = static fn() =>
            new UpdateLanguage(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\UI\Factory::class],
                $use[\ILIAS\Language\Language::class],
                static fn(): \ilRbacSystem => $GLOBALS['DIC']->rbac()->system(),
                // Same reasoning as InstallLanguage above: no database of
                // its own, ilSetupLanguage resolves it.
                $internal[\ilSetupLanguage::class],
                static fn(): int => \ilObjLanguageAccess::_lookupLangFolderRefId()
            );

        // LanguageLegacyInitialisationAdapter has no constructor of its own,
        // so it never needs the LanguageFileDirectoryManager argument -
        // it purely proxies to $DIC->language() at call time. This slot used
        // to be misleadingly named $internal[\ilLanguage::class] even though
        // it never held an \ilLanguage instance.
        $internal[Language\LanguageLegacyInitialisationAdapter::class] = static fn() =>
            new Language\LanguageLegacyInitialisationAdapter();

        // --- $implement --------------------------------------------------

        // This component registers TWO candidate implementations for
        // \ILIAS\Language\Language. Both assignments below are intentional -
        // ILIAS\Component\Dependencies\Reader::cacheImplement() collects every
        // $implement[...] assignment into a list rather than overwriting a
        // plain array key, and the generated RenamingDIC keeps each candidate
        // under its own offset, so neither line is dead code.
        // Consumers that $use[\ILIAS\Language\Language::class] (e.g. UI.php,
        // Setup.php, Refinery.php) are disambiguated per bootstrap entry point:
        //   - components/ILIAS/Setup/resources/dependency_resolution.php -> ilSetupLanguage
        //   - components/ILIAS/Init/resources/dependency_resolution.php  -> LanguageLegacyInitialisationAdapter
        // See docs/development/components-and-directories.md and cli/build_bootstrap.php.
        $implement[\ILIAS\Language\Language::class] = static fn() =>
            $internal[\ilSetupLanguage::class];

        $implement[\ILIAS\Language\Language::class] = static fn() =>
            $internal[Language\LanguageLegacyInitialisationAdapter::class];

        // --- $provide: services made available to the rest of the system -

        // Make the resolved language services available outside this component.
        $provide[LanguageFileDirectoryManager::class] = static fn() =>
            $internal[LanguageFileDirectoryManager::class];

        $provide[InstalledLanguageRepository::class] = static fn() =>
            $internal[InstalledLanguageDatabaseRepository::class];

        $provide[LanguageInstallationManager::class] = static fn() =>
            $internal[LanguageInstallationManager::class];

        // InstallLanguage used to also be provided under a dedicated
        // InstallLanguageInterface, justified by a since-corrected claim
        // that only the concrete class offered maybePerformAs(). That
        // method is declared on \ILIAS\Component\Activities\Activity itself
        // (see components/ILIAS/Component/src/Activities/Activity.php) and
        // thus available on every Activity implementation, InstallLanguage
        // included - so the dedicated interface added nothing its callers
        // relied on and was removed. The sole external consumer,
        // components/ILIAS/Init/Init.php, now pulls this concrete class
        // directly; AllModernComponents.php re-exposes that same resolved
        // instance under the legacy $DIC[InstallLanguage::class] key, which
        // is what ilObjLanguageFolderGUI reads. Setup Objectives never pull
        // this from the container at all: they receive the Activity
        // directly via $contribute[\ILIAS\Setup\Agent::class] above, or -
        // for callers not wired through the component graph - via
        // InstallLanguage::forSetup(). Because Init.php pulls this concrete
        // class (not an interface), InstallLanguage must stay non-final -
        // see the comment on its class declaration for why.
        $provide[InstallLanguage::class] = static fn() =>
            $internal[InstallLanguage::class];

        // Same reasoning and same legacy bridge as InstallLanguage above:
        // components/ILIAS/Init/Init.php pulls this concrete class too, and
        // AllModernComponents.php re-exposes that same resolved instance
        // under the legacy $DIC[UpdateLanguage::class] key, which is what
        // ilObjLanguageFolderGUI::refreshSelectedObject() reads. Setup
        // Objectives receive it the same way as InstallLanguage: via
        // $contribute[\ILIAS\Setup\Agent::class] below, or via
        // UpdateLanguage::forSetup() for callers not wired through the
        // component graph.
        $provide[UpdateLanguage::class] = static fn() =>
            $internal[UpdateLanguage::class];

        // --- $contribute: contributions to other components' collection
        //     points. Relative order preserved from before this file's
        //     reorganisation, since components may in general add multiple
        //     contributions to the same collection point sequentially (see
        //     docs/development/components-and-directories.md, "Contribute to
        //     Service or Functionality") - which the two
        //     \ILIAS\Component\Activities\Activity::class entries below
        //     actually do, one per Activity this component offers.

        $contribute[LanguageFileDirectory::class] = static fn() => new MainLanguageFileDirectory();

        $contribute[\ILIAS\Setup\Agent::class] = static fn() =>
            new \ilLanguageSetupAgent(
                $pull[\ILIAS\Refinery\Factory::class],
                $internal[\ilSetupLanguage::class],
                $internal[InstallLanguage::class],
                $internal[UpdateLanguage::class],
                $internal[InstalledLanguageDatabaseRepository::class]
            );

        $contribute[\ILIAS\Component\Activities\Activity::class] = static fn() =>
            $internal[InstallLanguage::class];

        $contribute[\ILIAS\Component\Activities\Activity::class] = static fn() =>
            $internal[UpdateLanguage::class];

        $contribute[User\Settings\UserSettings::class] = fn() =>
            new Language\UserSettings\Settings();
    }
}
