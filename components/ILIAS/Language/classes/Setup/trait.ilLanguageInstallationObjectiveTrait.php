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
use ILIAS\Language\Activities\InstallLanguageInterface;

/**
 * Shared dependencies and boilerplate for Objectives that install/update
 * languages via ilSetupLanguage and the InstallLanguage Activity. Used by
 * ilLanguagesInstalledAndUpdatedObjective and ilLanguagesUpdatedObjective;
 * not part of ilLanguageObjective itself because not every Objective in
 * this component needs these dependencies.
 */
trait ilLanguageInstallationObjectiveTrait
{
    protected \ilSetupLanguage $il_setup_language;
    protected InstallLanguageInterface $install_language;

    /**
     * @param InstallLanguageInterface|null $install_language The Activity to
     *        install with. Callers wired through the component graph (see
     *        ilLanguageSetupAgent) pass the resolved instance. Callers that
     *        are themselves constructed deep inside the Setup Objective tree
     *        - ilComponentPluginAdminInitObjective and
     *        ilPluginLanguageUpdatedObjective, reached via ilPluginDefaultAgent,
     *        which only ever receives a plugin name - have nothing to inject
     *        and omit it; a Setup-only instance is then built here. This is
     *        the single place that knows how, instead of each of those call
     *        sites reaching into $GLOBALS['DIC'] for a key that Setup never
     *        registers.
     */
    public function __construct(
        \ilSetupLanguage $il_setup_language,
        ?InstallLanguageInterface $install_language = null
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
     * @param list<string> $language_keys
     */
    protected function installLanguages(array $language_keys): void
    {
        $this->install_language->perform([
            'language_keys' => $language_keys,
        ]);
    }
}
