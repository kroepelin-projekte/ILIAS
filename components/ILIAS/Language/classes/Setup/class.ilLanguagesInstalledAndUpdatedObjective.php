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

use ILIAS\Setup;
use ILIAS\Language\Activities\InstallLanguage;
use ILIAS\Language\Activities\UpdateLanguage;

class ilLanguagesInstalledAndUpdatedObjective extends ilLanguageObjective
{
    protected \ilSetupLanguage $il_setup_language;
    protected InstallLanguage $install_language;
    protected UpdateLanguage $update_language;

    /**
     * @param InstallLanguage|null $install_language Callers wired through
     *        the component graph (see ilLanguageSetupAgent) pass the
     *        resolved instance. Callers that are themselves constructed
     *        deep inside the Setup Objective tree -
     *        ilComponentPluginAdminInitObjective and
     *        ilPluginLanguageUpdatedObjective, reached via
     *        ilPluginDefaultAgent, which only ever receives a plugin name -
     *        have nothing to inject and omit it; a Setup-only instance is
     *        then built here (InstallLanguage::forSetup(), which needs the
     *        concrete class, not just any Activity). This is the single
     *        place that knows how, instead of each of those call sites
     *        reaching into $GLOBALS['DIC'] for a key that Setup never
     *        registers.
     * @param UpdateLanguage|null $update_language Same reasoning as
     *        $install_language above.
     */
    public function __construct(
        \ilSetupLanguage $il_setup_language,
        ?InstallLanguage $install_language = null,
        ?UpdateLanguage $update_language = null
    ) {
        $this->il_setup_language = $il_setup_language;
        $this->install_language = $install_language ?? InstallLanguage::forSetup($il_setup_language);
        $this->update_language = $update_language ?? UpdateLanguage::forSetup($il_setup_language);
    }

    /**
     * Includes the language file directory configuration: an instance built with the component
     * graph's directories (ilLanguageSetupAgent) and one built with only the default directories
     * (plugin Setup Objectives, see ilSetupLanguage::usesDefaultLanguageFileDirectories()) are
     * different objectives. With a class-only hash the Setup would keep just one of them - and if
     * that was the default-directory one, it would refresh every language without the
     * component-contributed modules. The flag itself is part of the hash as well: the two instances
     * behave differently (see installLanguages()) even if their directories happen to be equal.
     */
    public function getHash(): string
    {
        $directories = [];
        foreach ($this->il_setup_language->getLanguageFileDirectoryManager()->getAllDirectories() as $directory) {
            $directories[] = implode('|', [
                $directory::class,
                $directory->getPrefix(),
                $directory->getPath(),
                $directory->getSuffix(),
            ]);
        }

        return hash("sha256", implode("\n", [
            self::class,
            $this->il_setup_language->usesDefaultLanguageFileDirectories() ? 'default-directories' : 'configured-directories',
            ...$directories,
        ]));
    }

    /**
     * Return installed languages
     */
    protected function getInstallLanguages(): array
    {
        return $this->il_setup_language->getInstalledLanguages() ?: ['en'];
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return "Install/Update languages " . implode(", ", $this->getInstallLanguages());
    }

    /**
     * @inheritDoc
     */
    public function isNotable(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getPreconditions(Setup\Environment $environment): array
    {
        return [
            new ilDatabaseInitializedObjective()
        ];
    }

    /**
     * @inheritDoc
     */
    public function achieve(Setup\Environment $environment): Setup\Environment
    {
        // Must come first: getInstallLanguages() reads from the database too.
        $this->useSetupDatabase($environment);
        $this->useSetupClientDataDir($environment);

        $language_keys = [];
        $invalid_language_keys = [];
        foreach ($this->getInstallLanguages() as $language_key) {
            if ($this->il_setup_language->checkLanguageForInstallation($language_key)) {
                $language_keys[] = $language_key;
            } else {
                $invalid_language_keys[] = $language_key;
            }
        }
        if ($invalid_language_keys !== []) {
            // as before the Component Revision: an invalid language file only skips that language,
            // it does not abort the whole Setup run
            $this->inform(
                $environment,
                'Skipped languages with invalid language files (left unchanged): '
                . implode(', ', $invalid_language_keys)
            );
        }

        $this->informAboutMissingClientDataDirectory($environment, $language_keys);
        $this->informAboutUnwritableOverlayDirectories($environment, $language_keys);
        $this->informAboutUnwrittenOverlays($environment, $this->installLanguages($language_keys));

        return $environment;
    }

    /**
     * Without a (resolvable, existing) client data directory - typically during "setup install",
     * before the client directory was created - no PO/MO file of a module maintained in PO files can
     * be written: its texts are served from the database until the next "setup update". Said so
     * explicitly instead of silently skipping the files.
     *
     * @param list<string> $language_keys
     */
    protected function informAboutMissingClientDataDirectory(Setup\Environment $environment, array $language_keys): void
    {
        if (
            $language_keys === []
            || $this->il_setup_language->getClientDataDir() !== null
            || !$this->il_setup_language->hasMigratedModules($language_keys)
        ) {
            return;
        }

        $this->inform(
            $environment,
            'WARNING: The client data directory is not known or does not exist yet, so the PO/MO language '
            . 'files of modules maintained in PO files are not written in this run - these modules are served '
            . 'from the database until then. Run "setup update" (as the web server user) once the client data '
            . 'directory exists.'
        );
    }

    /**
     * Checked BEFORE writing: the per-installation PO/MO files of modules maintained in PO files live
     * in the client data directory, and Setup must run as the web server user (a mandatory
     * requirement - the web server rewrites these files on every administrative change later on;
     * running Setup as root is not supported). The update itself is not aborted - the database is
     * written regardless and the files are repaired by the next run with sufficient permissions -,
     * but the problem is reported clearly instead of only being logged per module.
     *
     * @param list<string> $language_keys
     */
    protected function informAboutUnwritableOverlayDirectories(Setup\Environment $environment, array $language_keys): void
    {
        $unwritable_directories = $this->il_setup_language->findUnwritableOverlayDirectories($language_keys);
        if ($unwritable_directories !== []) {
            $this->inform(
                $environment,
                'WARNING: The PO/MO language files of modules maintained in PO files cannot be written to '
                . implode(', ', $unwritable_directories) . ' (not writable, or a file occupies the '
                . 'directory\'s place). The languages are still installed/updated in the database, but these '
                . 'modules keep showing outdated texts. Setup must be run as the web server user (the owner '
                . 'of the client data directory) - re-run "setup update" as that user to repair the files.'
            );
        }

        $this->informIfNotRunAsClientDataDirectoryOwner($environment, $language_keys);
    }

    /**
     * Writable for Setup is not enough: a Setup run as another user than the web server (typically
     * root, for whom every directory is writable) creates PO/MO files and directories the web server
     * cannot replace later on. The owner of the client data directory is taken as the web server
     * user; for a run as root, or as any other user than that owner, the overlay directories are
     * checked for writability by the owner, and a warning with the command that repairs the
     * ownership is given - also when every directory is writable right now, because what this run
     * creates belongs to the wrong user. Nothing is changed and nothing is aborted. Only checked
     * where the POSIX extension is available.
     *
     * @param list<string> $language_keys
     */
    protected function informIfNotRunAsClientDataDirectoryOwner(Setup\Environment $environment, array $language_keys = []): void
    {
        $client_data_dir = $this->il_setup_language->getClientDataDir();
        if ($client_data_dir === null || !function_exists('posix_geteuid')) {
            return;
        }
        $owner = @fileowner($client_data_dir);
        $current_user = posix_geteuid();
        if ($owner === false || ($owner === $current_user && $current_user !== 0)) {
            return;
        }
        // Only the overlay of modules maintained in PO files is written below the client data
        // directory by this objective - without such a module there is nothing to warn about
        if (!$this->il_setup_language->hasMigratedModules($language_keys)) {
            return;
        }

        $overlay_root = rtrim($client_data_dir, '/') . '/lang';
        if ($owner === 0) {
            $this->inform(
                $environment,
                sprintf(
                    'WARNING: Setup runs as user id %d, and the client data directory %s belongs to root. '
                    . 'Running Setup as root is not supported: the web server cannot replace the PO/MO '
                    . 'language files and directories of modules maintained in PO files that this run '
                    . 'creates. Fix: run "chown -R <web server user> %s" after this run, and run Setup '
                    . 'as the web server user from now on.',
                    $current_user,
                    $client_data_dir,
                    $overlay_root
                )
            );
            return;
        }

        $owner_info = function_exists('posix_getpwuid') ? @posix_getpwuid($owner) : false;
        $owner_name = is_array($owner_info) ? (string) $owner_info['name'] : (string) $owner;
        $unwritable_for_owner = $this->il_setup_language->findUnwritableOverlayDirectories($language_keys, $owner);

        $this->inform(
            $environment,
            sprintf(
                'WARNING: Setup runs as user id %d, but the client data directory %s belongs to user id %d. '
                . 'Running Setup as another user than the web server user (the owner of the client data '
                . 'directory) is not supported%s: the PO/MO language files and directories of modules '
                . 'maintained in PO files that this run creates belong to user id %d and cannot be '
                . 'replaced by the web server later on.%s Fix: run "chown -R %s %s" after this run, and '
                . 'run Setup as user %s from now on.',
                $current_user,
                $client_data_dir,
                $owner,
                $current_user === 0 ? ' (this includes root)' : '',
                $current_user,
                $unwritable_for_owner === []
                    ? ''
                    : ' Already not writable for user id ' . $owner . ': ' . implode(', ', $unwritable_for_owner) . '.',
                $owner_name,
                $overlay_root,
                $owner_name
            )
        );
    }

    /**
     * @param list<string> $language_keys languages whose PO/MO overlay could not be written, see
     *        installLanguages()
     */
    protected function informAboutUnwrittenOverlays(Setup\Environment $environment, array $language_keys): void
    {
        if ($language_keys === []) {
            return;
        }

        $this->inform(
            $environment,
            'WARNING: The PO/MO language files of modules maintained in PO files could not be written for '
            . 'the languages ' . implode(', ', $language_keys) . ' (see the PHP error log for details). '
            . 'Setup must be run as the web server user - re-run "setup update" as that user.'
        );
    }

    /**
     * @inheritDoc
     */
    public function isApplicable(Setup\Environment $environment): bool
    {
        return true;
    }

    /**
     * Hands the Setup-provided database resource to ilSetupLanguage, which
     * is the single place resolving it for everything that follows: the
     * Activity needs no database of its own, and ilSetupLanguage's
     * repository and manager re-read it on every call.
     *
     * achieve() must call this before reading anything from
     * ilSetupLanguage, so that the language list is determined against the
     * Setup database too.
     */
    protected function useSetupDatabase(Setup\Environment $environment): void
    {
        $this->il_setup_language->setDbHandler(
            $environment->getResource(Setup\Environment::RESOURCE_DATABASE)
        );
    }

    /**
     * The overlay of migrated modules lives in the client data directory. The Setup environment
     * knows it (ilias.ini `datadir` + client id) even when ilias.ini.php is not written yet; if it
     * does not, ilSetupLanguage falls back to reading ilias.ini.php itself.
     */
    protected function useSetupClientDataDir(Setup\Environment $environment): void
    {
        $ini = $environment->getResource(Setup\Environment::RESOURCE_ILIAS_INI);
        $client_id = $environment->getResource(Setup\Environment::RESOURCE_CLIENT_ID);
        if (!$ini instanceof ilIniFile || $client_id === null) {
            return;
        }

        $client_data_dir = \ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths::fromDataDirAndClientId(
            (string) $ini->readVariable('clients', 'datadir'),
            (string) $client_id
        );
        if ($client_data_dir !== null) {
            $this->il_setup_language->setClientDataDir($client_data_dir);
        }
    }

    protected function inform(Setup\Environment $environment, string $message): void
    {
        $io = $environment->getResource(Setup\Environment::RESOURCE_ADMIN_INTERACTION);
        if ($io instanceof Setup\AdminInteraction) {
            $io->inform($message);
            return;
        }
        error_log($message);
    }

    /**
     * Installs every given key that is not yet installed, and refreshes
     * every given key that already is - unconditionally, this Objective's
     * job (label "Install/Update languages") is to keep already-installed
     * languages in sync with the current language files on every setup.php
     * run (e.g. after a core update added or changed translations), not
     * just to install missing ones.
     *
     * Both Activities are handed the same full $language_keys list and each
     * decides for itself what applies to it: InstallLanguage::MODE_INSTALL
     * installs a not-yet-installed key and is a no-op for an already
     * installed one; UpdateLanguage refreshes an already installed key and
     * is a no-op for one that is not installed. Neither call needs the
     * caller to pre-filter by install status. Running UpdateLanguage on the
     * keys InstallLanguage just installed is intentional, not wasted work
     * avoided: it keeps this method's behaviour simple (one full list, two
     * independent Activities) and the freshly installed keys are also
     * freshly refreshed, which is harmless.
     *
     * achieve() only passes keys whose language files are valid (invalid
     * ones are skipped and reported there), so neither Activity's own
     * validation error is expected here.
     *
     * An instance that only knows the default language file directories
     * (see ilSetupLanguage::usesDefaultLanguageFileDirectories()) installs
     * missing languages but never refreshes installed ones: a refresh
     * without the component-contributed directories would delete those
     * modules' lng_data rows. The instance ilLanguageSetupAgent builds
     * with the component graph's directories does the refresh.
     *
     * @param list<string> $language_keys
     * @return list<string> the languages whose PO/MO overlay could not be written (see
     *         InstallLanguage/UpdateLanguage, "overlay_write_failed_language_keys")
     */
    protected function installLanguages(array $language_keys): array
    {
        if ($language_keys === []) {
            return [];
        }

        $install_result = $this->install_language->perform([
            'language_keys' => $language_keys,
            'mode' => InstallLanguage::MODE_INSTALL,
        ]);
        $overlay_write_failed = $install_result['overlay_write_failed_language_keys'] ?? [];

        if (!$this->il_setup_language->usesDefaultLanguageFileDirectories()) {
            $update_result = $this->update_language->perform([
                'language_keys' => $language_keys,
            ]);
            $overlay_write_failed = array_merge(
                $overlay_write_failed,
                $update_result['overlay_write_failed_language_keys'] ?? []
            );
        }

        return array_values(array_unique($overlay_write_failed));
    }
}
