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

namespace ILIAS\Language\ComponentTranslation;

/**
 * Optional addition to LanguageFileDirectory: a directory whose shipped `.po` files are not named
 * `<prefix>_<lang>.po`. Kept separate from LanguageFileDirectory so its implementations (and test
 * doubles) need not change - MigratedLanguageFilePaths::shippedFileNamePattern() falls back to
 * `<prefix>_%s` for every other directory.
 *
 * The file name must not depend on the module name (getPrefix()) where the module name is expected
 * to change, e.g. for a plugin that becomes a component later on: `ilias_%s` keeps the file name
 * stable across that move.
 */
interface NamesShippedLanguageFiles
{
    /**
     * The name of the shipped `.po` of a language without the ".po" extension: exactly one "%s"
     * (replaced by the language key), otherwise only letters, digits, "_", "-" and "." (no "/", no
     * ".."), e.g. "ilias_%s".
     */
    public function getShippedFileNamePattern(): string;
}
