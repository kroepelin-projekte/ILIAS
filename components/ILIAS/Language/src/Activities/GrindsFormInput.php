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

use ILIAS\Data\Result;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Component\Input\Field\Checkbox;
use ILIAS\UI\Component\Input\Field\Text as TextField;
use ILIAS\UI\Component\Input\Group as GroupInput;
use ILIAS\UI\Component\Input\Input;
use ILIAS\UI\Implementation\Component\Input\ArrayInputData;
use ILIAS\UI\Implementation\Component\Input\FormInputNameSource;
use ILIAS\UI\Implementation\Component\Input\InputInternal;

/**
 * getInputDescription() is declared to return the public FormInput interface, which does not
 * expose the machinery actually needed to collect input (withNameFrom()/withInput()/getContent());
 * that lives on InputInternal, which every concrete Input built via the FieldFactory passed to
 * getInputDescription() implements. grind() therefore requires the given FormInput to also
 * implement InputInternal.
 */
trait GrindsFormInput
{
    // protected, not private: an Activity that overrides maybePerformAs() to inject
    // additional parameters into perform() (e.g. AddLanguageEntry) still needs to call this
    // directly, without re-declaring GrindsFormInput itself.
    /**
     * @param array<mixed> $raw_parameters
     */
    protected function grind(FormInput $description, array $raw_parameters): Result
    {
        try {
            $named = $this->nameForGrinding($description);

            $flat_input = [];
            // Unknown keys are tolerated at this top level (getInputDescription()'s outermost
            // group is never itself validated against $raw_parameters), but rejected in every
            // nested group - see the $enforce_known_keys parameter below.
            $this->collectRawValues($named, $raw_parameters, $flat_input);

            $with_input = $named->withInput(new ArrayInputData($flat_input));
            $content = $with_input->getContent();

            if ($content->isError()) {
                return new Result\Error($this->describeInputError($with_input));
            }

            return new Result\Ok($content->value());
        } catch (InvalidInputException $e) {
            return new Result\Error($e);
        } catch (\InvalidArgumentException $e) {
            return new Result\Error(new InvalidInputException($e->getMessage(), 0, $e));
        } catch (\Throwable $e) {
            return new Result\Error(
                $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e)
            );
        }
    }

    private function nameForGrinding(FormInput $description): FormInput&InputInternal
    {
        if (!$description instanceof InputInternal) {
            throw new \LogicException(
                static::class . '::getInputDescription() must return a FormInput built from the ' .
                'UI framework (i.e. one that also implements ' . InputInternal::class . ') to be ' .
                'grindable by maybePerformAs() - see the GrindsFormInput trait for why.'
            );
        }

        /** @var FormInput&InputInternal $named */
        $named = $description->withNameFrom(new FormInputNameSource());

        return $named;
    }

    /**
     * @param array<string, mixed> $flat
     * @param bool $enforce_known_keys defaults to false, which is what grind() above uses for
     *        the outermost group of getInputDescription() - so an unrelated key anywhere in
     *        top-level $raw_parameters (e.g. a raw request array carrying other form fields
     *        alongside this Activity's own input) is silently ignored rather than rejected.
     *        Every nested group, however, is always recursed into with true (see the call
     *        below), so an unknown key inside any such group IS rejected - unlike the top
     *        level, a group's own keys come entirely from this Activity's own
     *        getInputDescription(), so a typo or stale key there should fail loudly instead of
     *        silently vanishing.
     */
    private function collectRawValues(Input $named, mixed $raw_value, array &$flat, bool $enforce_known_keys = false): void
    {
        if ($named instanceof GroupInput) {
            $sub_raw = is_array($raw_value) ? $raw_value : [];

            if ($enforce_known_keys) {
                $unknown_keys = array_diff(array_keys($sub_raw), array_keys($named->getInputs()));
                if ($unknown_keys !== []) {
                    throw new InvalidInputException(
                        'Unknown key(s) for ' . $this->fieldLabelForErrorMessage($named) . ': '
                        . implode(', ', array_map(static fn(int|string $key): string => (string) $key, $unknown_keys))
                    );
                }
            }

            foreach ($named->getInputs() as $key => $child) {
                $this->collectRawValues($child, $sub_raw[$key] ?? null, $flat, true);
            }
            return;
        }

        if (!$named instanceof InputInternal) {
            throw new \LogicException(
                'Every leaf field of a grindable FormInput must implement ' . InputInternal::class . '.'
            );
        }

        $name = $named->getName();
        if ($name === null) {
            throw new \LogicException('Every field of a grindable FormInput must have a name.');
        }

        $flat[$name] = match (true) {
            $named instanceof Checkbox => $this->normalizeCheckboxRawValue($raw_value),
            $named instanceof TextField && is_array($raw_value) => $this->joinListOfStringsRawValue($raw_value),
            default => $raw_value ?? '',
        };
    }

    /**
     * Returns $raw_value joined into a single comma-separated string if every one of its items
     * is a string; otherwise returns $raw_value unchanged, in whatever shape the caller passed.
     *
     * @param array<mixed> $raw_value
     * @return array<mixed>|string
     */
    private function joinListOfStringsRawValue(array $raw_value): array|string
    {
        foreach ($raw_value as $item) {
            if (!is_string($item)) {
                return $raw_value;
            }
        }

        return implode(',', $raw_value);
    }

    private function normalizeCheckboxRawValue(mixed $value): string
    {
        if (
            $value === true || $value === 1 || $value === '1' || $value === 'true'
            || $value === 'checked' || $value === 'on'
        ) {
            return 'checked';
        }
        if (
            $value === false || $value === 0 || $value === '0'
            || $value === '' || $value === 'false' || $value === null
        ) {
            return '';
        }

        throw new \InvalidArgumentException(
            'Expected a boolean or one of the common primitive representations of true/false ' .
            '(true/false, 1/0, "1"/"0", "true"/"false", "checked"/"on", "" or null), got: '
            . get_debug_type($value)
        );
    }

    private function describeInputError(FormInput $with_input): InvalidInputException
    {
        $field_errors = [];
        $this->collectFieldErrors($with_input, $field_errors);

        if ($field_errors === []) {
            $error = $with_input instanceof InputInternal ? $with_input->getError() : null;
            $field_errors[] = $error ?? 'Invalid input.';
        }

        return new InvalidInputException(implode('; ', $field_errors));
    }

    /**
     * @param list<string> $field_errors
     */
    private function collectFieldErrors(Input $named, array &$field_errors): void
    {
        if ($named instanceof GroupInput) {
            foreach ($named->getInputs() as $child) {
                $this->collectFieldErrors($child, $field_errors);
            }
            return;
        }

        if (!$named instanceof InputInternal) {
            return;
        }

        $error = $named->getError();
        if ($error !== null) {
            $field_errors[] = $this->fieldLabelForErrorMessage($named) . ': ' . $error;
        }
    }

    private function fieldLabelForErrorMessage(Input $named): string
    {
        if ($named instanceof \ILIAS\UI\Implementation\Component\Input\Input) {
            $dedicated_name = $named->getDedicatedName();
            if ($dedicated_name !== null) {
                return $dedicated_name;
            }
        }

        return $named instanceof InputInternal ? ($named->getName() ?? '?') : '?';
    }
}
