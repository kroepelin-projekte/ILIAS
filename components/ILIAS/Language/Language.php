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
        $contribute[LanguageFileDirectory::class] = static fn() => new MainLanguageFileDirectory();

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

        $contribute[\ILIAS\Setup\Agent::class] = static fn() =>
            new \ilLanguageSetupAgent(
                $pull[\ILIAS\Refinery\Factory::class],
                $internal[\ilSetupLanguage::class],
                $internal[InstallLanguage::class],
                $internal[InstalledLanguageDatabaseRepository::class]
            );

        // Read (InstalledLanguageRepository) and write (LanguageInstallationManager)
        // access to the language installation domain, extracted from
        // ilSetupLanguage per docs/development/repository-pattern.md - see that
        // class' docblock. ilSetupLanguage itself keeps delegating to both and
        // remains the \ILIAS\Language\Language implementation used during Setup
        // (see $implement[...] below); these two are for consumers that only
        // need install/retrieval behaviour, not txt().
        $internal[InstalledLanguageDatabaseRepository::class] = static fn() =>
            new InstalledLanguageDatabaseRepository(
                static fn(): \ilDBInterface => $GLOBALS['ilDB'] ?? $GLOBALS['DIC']->database(),
                $internal[LanguageFileDirectoryManager::class],
                (string) realpath(__DIR__ . '/../../../')
            );
        $provide[InstalledLanguageRepository::class] = static fn() =>
            $internal[InstalledLanguageDatabaseRepository::class];

        $internal[LanguageInstallationManager::class] = static fn() =>
            new LanguageInstallationManager(
                static fn(): \ilDBInterface => $GLOBALS['ilDB'] ?? $GLOBALS['DIC']->database(),
                $internal[LanguageFileDirectoryManager::class],
                (string) realpath(__DIR__ . '/../../../'),
                $internal[InstalledLanguageDatabaseRepository::class]
            );
        $provide[LanguageInstallationManager::class] = static fn() =>
            $internal[LanguageInstallationManager::class];

        $internal[InstallLanguage::class] = static fn() =>
            new InstallLanguage(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\UI\Factory::class],
                $use[\ILIAS\Language\Language::class],
                static fn(): \ilRbacSystem => $GLOBALS['DIC']->rbac()->system(),
                // Setup Objectives (see ilLanguageInstallationObjectiveTrait)
                // call perform() directly - without a runtime $DIC - after
                // temporarily swapping $GLOBALS['ilDB'] to the Setup-provided
                // database resource, following the same
                // $GLOBALS['ilDB'] ?? $DIC->database() convention already
                // used elsewhere for this Setup/Runtime split (see
                // arConnectorDB::__construct()). $GLOBALS['ilDB'] is also
                // populated at runtime by ilInitialisation::initDatabase()
                // via initGlobal(), so this works for both entry points.
                static fn(): \ilDBInterface => $GLOBALS['ilDB'] ?? $GLOBALS['DIC']->database(),
                $internal[\ilSetupLanguage::class],
                static fn(): int => \ilObjLanguageAccess::_lookupLangFolderRefId()
            );

        $contribute[\ILIAS\Component\Activities\Activity::class] = static fn() =>
            $internal[InstallLanguage::class];

        $internal[LanguageFileDirectoryManager::class] = static fn() =>
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                ...$seek[LanguageFileDirectory::class]
            );

        // Make the resolved language services available outside this component.
        $provide[LanguageFileDirectoryManager::class] = static fn() =>
            $internal[LanguageFileDirectoryManager::class];
        // Both provides below resolve to the same InstallLanguage instance but
        // serve two distinct consumer contracts: the Setup Objectives only
        // need perform() (declared on InstallLanguageInterface), while
        // ilObjLanguageFolderGUI additionally needs maybePerformAs()
        // (permission check + Result wrapping), which only exists on the
        // concrete ActivityImpl-based class, not on the interface.
        $provide[\ILIAS\Language\Activities\InstallLanguageInterface::class] = static fn() =>
            $internal[InstallLanguage::class];
        $provide[InstallLanguage::class] = static fn() =>
            $internal[InstallLanguage::class];

        $internal[\ilSetupLanguage::class] = static fn() =>
            new \ilSetupLanguage(
                "en",
                $internal[LanguageFileDirectoryManager::class]
            );

        // LanguageLegacyInitialisationAdapter has no constructor of its own,
        // so it never needs the LanguageFileDirectoryManager argument -
        // it purely proxies to $DIC->language() at call time. This slot used
        // to be misleadingly named $internal[\ilLanguage::class] even though
        // it never held an \ilLanguage instance.
        $internal[Language\LanguageLegacyInitialisationAdapter::class] = static fn() =>
            new Language\LanguageLegacyInitialisationAdapter();

        $contribute[User\Settings\UserSettings::class] = fn() =>
            new Language\UserSettings\Settings();
    }
}
