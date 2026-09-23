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

use ILIAS\Language\Activities\SafeToDisplayActivityError;

/**
 * The serialized module array just written to lng_modules could not be read back - almost always a
 * wrong collation of lng_data/lng_modules (see mantis #20046 and #19140). The message only embeds
 * the module and language key, so it is safe to show (escaped) to an administrator.
 */
final class LanguageDataNotSavedException extends \RuntimeException implements SafeToDisplayActivityError
{
    public function __construct(string $module, string $lang_key)
    {
        parent::__construct(sprintf(
            "Data for module '%s' of language '%s' is not correctly saved. "
            . "Please check the collation of your database tables lng_data and lng_modules. It must be utf8_unicode_ci.",
            $module,
            $lang_key
        ));
    }
}
