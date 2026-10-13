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
use ILIAS\Language\Activities\UninstallLanguage;
use ILIAS\Language\Activities\RemoveLocalLanguageChanges;
use ILIAS\Language\Activities\AddLanguageEntry;
use ILIAS\Language\Activities\SetLanguageDetectionEnabled;
use ILIAS\Language\Activities\SetLanguageTranslationEnabled;
use ILIAS\Language\Setup\InstalledLanguageDatabaseRepository;
use ILIAS\Environment\Configuration\Installation\Directories;
use ILIAS\Environment\Configuration\Installation\ClientIdProvider;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Logging\Logger\LoggerFactoryInterface;

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

        // A Closure, not a value: init() runs without a booted $DIC.
        $settings = static fn(): \ILIAS\Administration\Setting => $GLOBALS['DIC']->settings();

        // $internal: ordered so each entry's dependencies are declared above it.

        $internal[Language\LanguageFolder::class] = static fn() =>
            new Language\LanguageFolderLegacyProxy();

        $internal[LanguageFileDirectoryManager::class] = static fn() =>
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                ...$seek[LanguageFileDirectory::class]
            );

        // Read access to the installation domain, extracted from ilSetupLanguage (repository-pattern.md).
        $internal[InstalledLanguageDatabaseRepository::class] = static fn() =>
            new InstalledLanguageDatabaseRepository(
                $pull[\ILIAS\Database\Connection::class],
                $internal[LanguageFileDirectoryManager::class],
                $use[Directories::class]->getRoot()
            );

        // Setup instance: Setup hands in its own database via setDbHandler()
        // instead of the Connection (see components/ILIAS/Database/ROADMAP.md).
        $internal[\ilSetupLanguage::class] = static fn() =>
            new \ilSetupLanguage(
                "en",
                $internal[LanguageFileDirectoryManager::class]
            );

        // $use must be read inside a container closure: the bootstrap only wires what is read there
        $client_data_dir_resolver_of = static function (
            Directories $directories,
            ClientIdProvider $client_id_provider
        ): \Closure {
            return static function () use ($directories, $client_id_provider): ?string {
                try {
                    $client_id = $client_id_provider->getClientId()->toString();
                } catch (\InvalidArgumentException) {
                    // Missing or invalid client id: no overlay instead of a failing request.
                    return null;
                }
                return MigratedLanguageFilePaths::fromDataDirAndClientId($directories->getDataDir(), $client_id);
            };
        };

        $runtime_setup_language = static fn(): \ilSetupLanguage =>
            new \ilSetupLanguage(
                "en",
                $internal[LanguageFileDirectoryManager::class],
                $pull[\ILIAS\Database\Connection::class],
                $use[LoggerFactoryInterface::class]->getLazy('lang'),
                $client_data_dir_resolver_of($use[Directories::class], $use[ClientIdProvider::class])
            );

        $internal[InstallLanguage::class] = static fn() =>
            new InstallLanguage(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\Language\Language::class],
                $use[\ILIAS\AccessControl\PublicInterface\RBAC::class],
                $runtime_setup_language(),
                $internal[Language\LanguageFolder::class]
            );

        $internal[UpdateLanguage::class] = static fn() =>
            new UpdateLanguage(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\Language\Language::class],
                $use[\ILIAS\AccessControl\PublicInterface\RBAC::class],
                $runtime_setup_language(),
                $internal[Language\LanguageFolder::class]
            );

        // Runtime-only: no ilSetupLanguage and no forSetup(); the legacy collaborators stay at their defaults.
        $internal[UninstallLanguage::class] = static fn() =>
            new UninstallLanguage(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\Language\Language::class],
                $use[\ILIAS\AccessControl\PublicInterface\RBAC::class],
                $internal[Language\LanguageFolder::class]
            );

        // Runtime-only like UninstallLanguage; the legacy collaborators stay at their defaults.
        $internal[RemoveLocalLanguageChanges::class] = static fn() =>
            new RemoveLocalLanguageChanges(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\Language\Language::class],
                $use[\ILIAS\AccessControl\PublicInterface\RBAC::class],
                $internal[Language\LanguageFolder::class],
                $use[LoggerFactoryInterface::class]->getLazy('lang')
            );

        // Runtime-only (no forSetup()); the two legacy closures of AddLanguageEntry stay at their defaults.
        $internal[AddLanguageEntry::class] = static fn() =>
            new AddLanguageEntry(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\Language\Language::class],
                $use[\ILIAS\AccessControl\PublicInterface\RBAC::class],
                $internal[InstalledLanguageDatabaseRepository::class],
                $pull[\ILIAS\Database\Connection::class],
                $internal[Language\LanguageFolder::class],
                $internal[LanguageFileDirectoryManager::class],
                $use[Directories::class]->getRoot(),
                $client_data_dir_resolver_of($use[Directories::class], $use[ClientIdProvider::class]),
                // replace_lang_entry/update_module_cache/user_login: defaults of AddLanguageEntry
            );

        // Reads/writes the single "lang_detection" setting only.
        $internal[SetLanguageDetectionEnabled::class] = static fn() =>
            new SetLanguageDetectionEnabled(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\Language\Language::class],
                $use[\ILIAS\AccessControl\PublicInterface\RBAC::class],
                $settings,
                $internal[Language\LanguageFolder::class]
            );

        // Needs the installed-language repository to validate that the language_key is installed.
        $internal[SetLanguageTranslationEnabled::class] = static fn() =>
            new SetLanguageTranslationEnabled(
                $pull[\ILIAS\Refinery\Factory::class],
                $use[\ILIAS\Language\Language::class],
                $use[\ILIAS\AccessControl\PublicInterface\RBAC::class],
                $settings,
                $internal[InstalledLanguageDatabaseRepository::class],
                $internal[Language\LanguageFolder::class],
            );

        // No constructor: it only proxies to $DIC->language() at call time.
        $internal[Language\LanguageLegacyInitialisationAdapter::class] = static fn() =>
            new Language\LanguageLegacyInitialisationAdapter();

        // Both implementations are intentional: Dependencies\Reader::cacheImplement() collects every assignment.
        // Setup resolves ilSetupLanguage, Init the LanguageLegacyInitialisationAdapter (see the dependency_resolution.php files).
        $implement[\ILIAS\Language\Language::class] = static fn() =>
            $internal[\ilSetupLanguage::class];

        $implement[\ILIAS\Language\Language::class] = static fn() =>
            $internal[Language\LanguageLegacyInitialisationAdapter::class];

        // --- $provide ---
        $provide[LanguageFileDirectoryManager::class] = static fn() =>
            $internal[LanguageFileDirectoryManager::class];

        // --- $contribute ---

        $contribute[LanguageFileDirectory::class] = static fn() => new MainLanguageFileDirectory();

        $contribute[\ILIAS\Setup\Agent::class] = static fn() =>
            new \ilLanguageSetupAgent(
                $pull[\ILIAS\Refinery\Factory::class],
                $internal[\ilSetupLanguage::class],
                // Setup checks no permissions, hence no RBAC.
                InstallLanguage::forSetup($internal[\ilSetupLanguage::class]),
                // forSetup() also does not re-apply the plugin language files (the plugin
                // objectives do that, and the Setup has no component repository in $DIC)
                UpdateLanguage::forSetup($internal[\ilSetupLanguage::class])
            );

        // The Activities are provided under their concrete class (Init.php pulls them specifically), so none may become final:
        // the GUI tests create PHPUnit mocks of them. Contribute order must match $activities' order (RenamingDIC numbers the
        // repeated Activity key in call order, see LanguageComponentGraphTest) and the loop stays between the Agent and UserSettings entries.
        $activities = [
            InstallLanguage::class,
            UpdateLanguage::class,
            UninstallLanguage::class,
            RemoveLocalLanguageChanges::class,
            AddLanguageEntry::class,
            SetLanguageDetectionEnabled::class,
            SetLanguageTranslationEnabled::class,
        ];
        foreach ($activities as $activity) {
            $provide[$activity] = static fn() => $internal[$activity];
            $contribute[\ILIAS\Component\Activities\Activity::class] = static fn() => $internal[$activity];
        }

        $contribute[User\Settings\UserSettings::class] = fn() =>
            new Language\UserSettings\Settings();
    }
}
