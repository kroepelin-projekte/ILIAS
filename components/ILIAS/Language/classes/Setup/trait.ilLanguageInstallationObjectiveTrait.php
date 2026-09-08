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
use ILIAS\Component\Activities\Activity;
use ILIAS\Language\Activities\InstallLanguage;

/**
 * Shared dependencies and boilerplate for Objectives that install/update
 * languages via ilSetupLanguage (see installLanguages() below for why this
 * no longer goes through the InstallLanguage Activity). Not part of
 * ilLanguageObjective itself because not every Objective in this component
 * needs these dependencies (ilDefaultLanguageSetObjective does not).
 *
 * Currently only ilLanguagesInstalledAndUpdatedObjective uses this - the
 * former second user, ilLanguagesUpdatedObjective, was unused since
 * install and update were merged into one Objective and has been removed.
 * If no further Objective needs these, the trait can be folded into its
 * single user.
 */
trait ilLanguageInstallationObjectiveTrait
{
    protected \ilSetupLanguage $il_setup_language;

    /**
     * No longer read by installLanguages() (see that method's docblock) -
     * kept as part of the constructor contract regardless, since
     * ilLanguageSetupAgent and Language.php's component-graph wiring both
     * already pass a resolved instance in, and changing that wiring is out
     * of scope for the fix that made this property unused.
     */
    protected Activity $install_language;

    /**
     * @param Activity|null $install_language Accepted for the reasons
     *        above, not otherwise used by this trait. Callers wired through
     *        the component graph (see ilLanguageSetupAgent) pass the
     *        resolved instance. Callers that are themselves constructed
     *        deep inside the Setup Objective tree - ilComponentPluginAdminInitObjective
     *        and ilPluginLanguageUpdatedObjective, reached via
     *        ilPluginDefaultAgent, which only ever receives a plugin name -
     *        have nothing to inject and omit it; a Setup-only instance is
     *        then built here. This is the single place that knows how,
     *        instead of each of those call sites reaching into
     *        $GLOBALS['DIC'] for a key that Setup never registers.
     */
    public function __construct(
        \ilSetupLanguage $il_setup_language,
        ?Activity $install_language = null
    ) {
        $this->il_setup_language = $il_setup_language;
        $this->install_language = $install_language ?? InstallLanguage::forSetup($il_setup_language);
    }

    /**
     * Hands the Setup-provided database resource to ilSetupLanguage, which
     * is the single place resolving it for everything that follows: the
     * Activity needs no database of its own, and ilSetupLanguage's
     * repository and manager re-read it on every call.
     *
     * achieve() must call this before reading anything from
     * ilSetupLanguage, so that the language list is determined against the
     * Setup database too. Overwriting $GLOBALS['ilDB'] around the install -
     * which this trait used to do, with a TODO attached - is no longer
     * necessary.
     */
    protected function useSetupDatabase(Setup\Environment $environment): void
    {
        $this->il_setup_language->setDbHandler(
            $environment->getResource(Setup\Environment::RESOURCE_DATABASE)
        );
    }

    /**
     * Installs every given key that is not yet installed, and re-installs
     * (refreshes) every given key that already is - unconditionally, this
     * Objective's job (see ilLanguagesInstalledAndUpdatedObjective's label
     * "Install/Update languages") is to keep already-installed languages in
     * sync with the current language files on every setup.php run (e.g.
     * after a core update added or changed translations), not just to
     * install missing ones.
     *
     * This is deliberately NOT routed through
     * $this->install_language->perform(): that Activity's "install"/
     * "install_local" modes exist for the GUI's "Install"/"Install local"
     * commands, where mode "install" treats an already-installed language
     * as a no-op by design (see InstallLanguage::perform()) - the exact
     * opposite of what this Objective needs. The flush+insert+register
     * sequence below is instead applied directly via $il_setup_language,
     * unconditionally for every key - mirroring what
     * ilObjLanguage::refresh() does for the equivalent runtime "refresh"
     * GUI command.
     *
     * @param list<string> $language_keys
     */
    protected function installLanguages(array $language_keys): void
    {
        $error_language_keys = [];
        foreach ($language_keys as $language_key) {
            if (!$this->il_setup_language->checkLanguageForInstallation($language_key)) {
                $error_language_keys[] = $language_key;
            }
        }

        if ($error_language_keys !== []) {
            throw new \RuntimeException(
                'Invalid language files: ' . implode(', ', $error_language_keys)
            );
        }

        $db_languages = $this->il_setup_language->getAvailableLanguagesForInstallation();
        $local_language_keys = $this->il_setup_language->getLocalLanguages();

        foreach ($language_keys as $language_key) {
            $this->il_setup_language->flushLanguageForInstallation($language_key);
            $this->il_setup_language->insertLanguageForInstallation($language_key);
            $this->il_setup_language->registerInstalledLanguage($language_key, $db_languages, $local_language_keys);
        }
    }
}
