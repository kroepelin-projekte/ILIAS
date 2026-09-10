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
 * Marker interface for a \Throwable a maybePerformAs() Result\Error can
 * carry whose getMessage() is ALREADY a concrete, actionable, non-sensitive
 * message meant to be shown to the end user as-is - as opposed to every
 * other \Throwable, which is an unexpected *internal* failure whose raw
 * message must never be shown directly. See
 * \ILIAS\Language\RendersActivityErrors::activityErrorMessage() for how
 * this decides what to show/log.
 *
 * Implemented by InvalidInputException and AmbiguousLanguageTitleException
 * (see their own class docblocks).
 */
interface SafeToDisplayActivityError extends \Throwable
{
}
