<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with
 * the source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

namespace ILIAS\Language\Tests\Activities;

use ILIAS\Language\Activities\InstallLanguage;
use ILIAS\Language\Language;
use ILIAS\Refinery\Factory as RefineryFactory;
use PHPUnit\Framework\MockObject\MockObject;
use ilSetupLanguage;

trait InstallLanguageTestFixture
{
    protected function createActivity(
        ilSetupLanguage $setup_language,
        ?\ilRbacSystem $rbac = null,
        ?Language $language = null
    ): InstallLanguage {
        return new InstallLanguage(
            $this->createStub(RefineryFactory::class),
            $language ?? $this->createStub(Language::class),
            $this->rbacOf($rbac ?? $this->createStub(\ilRbacSystem::class)),
            $setup_language,
            $this->languageFolder()
        );
    }

    protected function createSetupLanguageMock(
        array $available_languages,
        array $local_language_keys,
        array $installed_language_keys,
        array $invalid_local_language_files = []
    ): MockObject&ilSetupLanguage {
        $setup_language = $this->createMock(ilSetupLanguage::class);
        $setup_language->method('getAvailableLanguagesForInstallation')->willReturn($available_languages);
        $setup_language->method('getLocalLanguages')->willReturn($local_language_keys);
        $setup_language->method('getInstalledLanguages')->willReturn($installed_language_keys);
        $setup_language->method('getInvalidLocalLanguageFiles')->willReturn($invalid_local_language_files);

        return $setup_language;
    }
}
