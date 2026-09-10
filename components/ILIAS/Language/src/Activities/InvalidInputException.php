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
 * Thrown by GrindsFormInput::grind() when $raw_parameters, once run through
 * an Activity's own getInputDescription(), turns out to violate the
 * declared FormInput's own constraints (e.g. a required field left blank,
 * an unknown key in a Group) - as opposed to every other \Throwable
 * grind()/maybePerformAs() can produce, which signals an unexpected
 * *internal* failure (e.g. a database error).
 *
 * getMessage() here is always already a concrete, field-attributed
 * description built from the actual FormInput tree's own (localized)
 * per-field error messages - see GrindsFormInput::describeInputError() -
 * never a raw, potentially sensitive internal detail, so showing it
 * directly to an end user (see SafeToDisplayActivityError, which this
 * implements) is safe.
 */
class InvalidInputException extends \InvalidArgumentException implements SafeToDisplayActivityError
{
}
