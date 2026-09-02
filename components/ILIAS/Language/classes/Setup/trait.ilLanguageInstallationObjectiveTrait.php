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

    public function __construct(
        \ilSetupLanguage $il_setup_language,
        InstallLanguageInterface $install_language
    ) {
        $this->il_setup_language = $il_setup_language;
        $this->install_language = $install_language;
    }

    /**
     * Temporarily swaps the global $ilDB for the Setup-provided database
     * resource, runs the Activity with it, and restores the previous
     * global afterwards. Shared by the language install/update Objectives
     * to avoid duplicating this boilerplate.
     *
     * @param list<string> $language_keys
     */
    protected function installLanguagesWithSetupDb(Setup\Environment $environment, array $language_keys): void
    {
        $db = $environment->getResource(Setup\Environment::RESOURCE_DATABASE);

        // TODO: Remove this once ilSetupLanguage (or a successor) supports proper
        // DI for all methods.
        $db_tmp = $GLOBALS["ilDB"];
        $GLOBALS["ilDB"] = $db;

        try {
            $this->il_setup_language->setDbHandler($db);
            $this->install_language->perform([
                'language_keys' => $language_keys,
            ]);
        } finally {
            $GLOBALS["ilDB"] = $db_tmp;
        }
    }
}
