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

namespace ILIAS\Language;

/**
 * A language identifier as an enum case: the backing value is the identifier, module() the language
 * module it belongs to. Implemented by a (string backed) enum per module, generated from the module's
 * `.pot` (see components/ILIAS/Language/tools/language-identifier-enum/) and passed to
 * \ilLanguage::translate() - which then reads the identifier from exactly this module, without a
 * loadLanguageModule() beforehand and without depending on which module was loaded last.
 */
interface LanguageIdentifier extends \BackedEnum
{
    /**
     * The language module the identifier belongs to, e.g. "poll".
     */
    public function module(): string;
}
