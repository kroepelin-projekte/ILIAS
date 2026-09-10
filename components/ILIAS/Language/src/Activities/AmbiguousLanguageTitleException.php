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

namespace ILIAS\Language\Activities;

/**
 * Thrown by UninstallLanguage::perform() / RemoveLocalLanguageChanges::perform()
 * (see ResolvesLanguageKeysToObjIds::resolveObjIdsByLanguageKey()) when a
 * requested language key cannot be unambiguously resolved to a single "lng"
 * object, because more than one such object shares the same title - a data
 * integrity anomaly that should never occur in a healthy installation, but
 * is not otherwise guarded against anywhere in this component.
 *
 * Unlike every other \Throwable perform() can raise (e.g. a database
 * error), this one names a concrete, non-sensitive, admin-actionable
 * problem (which title is duplicated) - so, like InvalidInputException, it
 * implements SafeToDisplayActivityError: activityErrorMessage() (see
 * \ILIAS\Language\RendersActivityErrors) shows its
 * message directly, instead of hiding it behind a generic "action aborted"
 * message the admin could not act on.
 */
class AmbiguousLanguageTitleException extends \RuntimeException implements SafeToDisplayActivityError
{
}
