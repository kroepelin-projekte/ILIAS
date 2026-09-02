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

namespace ILIAS\Language\Setup;

use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;

/**
 * Small, side-effect-free helpers for reading *.lang files, shared by
 * InstalledLanguageDatabaseRepository (validation) and
 * LanguageInstallationManager (import into the database). Kept as a trait
 * rather than a base class so both can also extend other things if needed
 * and to avoid a dependency of one on the other just for these helpers.
 */
trait LanguageFileParsing
{
    private function absoluteDirectoryPath(string $absolute_path, LanguageFileDirectory $directory): string
    {
        return rtrim($absolute_path, '/') . '/' . ltrim($directory->getPath(), '/');
    }

    /**
     * Remove *.lang header information from '$content'.
     *
     * This function seeks for a special keyword where the language information starts.
     * If found it returns the plain language information; otherwise returns false.
     *
     * @param string[] $content expect an ILIAS lang-file
     * @return bool|string[]
     */
    private function cutHeader(array $content)
    {
        foreach ($content as $key => $val) {
            if (trim($val) === "<!-- language file start -->") {
                return array_slice($content, $key + 1);
            }
        }
        return false;
    }
}
