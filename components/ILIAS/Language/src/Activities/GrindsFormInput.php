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
 * Shared "grinding" mechanism for every Activity in this component: turns
 * the raw, primitive $raw_parameters array handed to maybePerformAs() into
 * the exact parameters shape isAllowedToPerform()/perform() expect, by
 * actually running $raw_parameters through the FormInput tree the Activity
 * itself declares via getInputDescription(). This is exactly what
 * components/ILIAS/Component/src/Activities/Activity.php and README.md ("As
 * Implementor") require:
 *  - "maybePerformAs shall use getInputDescription, isAllowedToPerform and
 *    perform in a very specific pattern."
 *  - "If an input is accepted by getInputDescription it shall not make
 *    isAllowedToPerform or perform crash."
 * Before this trait was introduced, none of the Activities in this
 * component did this: every one of them read $raw_parameters directly in a
 * private normalizeParameters(), never touching getInputDescription() at
 * all - the FormInput it returned was pure, never-verified documentation.
 *
 * ## Why the downcast to InputInternal is necessary and safe
 *
 * getInputDescription() is declared to return the public
 * \ILIAS\UI\Component\Input\Container\Form\FormInput interface, which
 * deliberately does not expose the machinery actually needed to collect
 * input (`withNameFrom()`/`withInput()`/`getContent()`) - that machinery
 * lives on \ILIAS\UI\Implementation\Component\Input\InputInternal, the only
 * interface every concrete Input the UI framework ships implements (see
 * InputInternal's own docblock: "might better be ILIAS/UI/Input/Input, but
 * we would need to promote many properties there before."). Since every
 * Activity's getInputDescription() in this component is built exclusively
 * from `$ui_factory->input()->field()->...()` calls - i.e. always a real UI
 * framework implementation, never a bespoke FormInput - this downcast is
 * safe in practice. grind() still checks it explicitly and reports a clean
 * Result\Error (never a crash) if some future getInputDescription() were to
 * return something that does not comply.
 *
 * ## Why recursing into groups needs no such downcast
 *
 * Unlike the collection machinery, `getInputs()` (used to walk a Group's
 * children) IS already part of the public API - see
 * \ILIAS\UI\Component\Input\Group::getInputs() - so recognising and
 * recursing into a Group never needs the internal interface at all. Note
 * that Group::getInputs() is declared to return `Input[]`, not
 * `FormInput[]` (Group is a monoid operation over the more general Input
 * interface) - collectRawValues()/collectFieldErrors() below are typed
 * against `Input` accordingly, narrowing to FormInput/InputInternal
 * themselves via instanceof where actually needed.
 *
 * ## Array raw values for a Text field (language_keys)
 *
 * InstallLanguage/UpdateLanguage/UninstallLanguage/RemoveLocalLanguageChanges
 * all declare `language_keys` as a single Text field ("comma-separated list
 * of language keys"), while every GUI caller in this component
 * (class.ilObjLanguageFolderGUI.php) has always built and passed it as a
 * PHP array of strings - a contract intentionally kept (see
 * toLanguageKeyList() on each of those Activities, which accepts both a
 * string and an array). A Text field's own withValue() rejects any
 * non-string value outright (see
 * \ILIAS\UI\Implementation\Component\Input\Field\Text::isClientSideValueOk()),
 * so an array raw value must be joined into the same comma-separated string
 * shape a real HTML input would carry BEFORE it ever reaches withInput() -
 * see collectRawValues()/joinListOfStringsRawValue().
 *
 * ## Checkbox tolerance for maybePerformAs()
 *
 * See normalizeCheckboxRawValue() for how this reuses, rather than
 * reimplements, Checkbox::withInput()'s own true/false decision.
 *
 * ## Every rejection of $raw_parameters is a SafeToDisplayActivityError
 *
 * grind() (see below) converts every way $raw_parameters can be rejected -
 * a required field left blank, an unknown Group key, or the UI framework's
 * own `Input::withValue()` throwing a blank `\InvalidArgumentException` for
 * a value of the wrong type - into an InvalidInputException, so a caller
 * (e.g. RendersActivityErrors::activityErrorMessage()) always shows it to
 * the end user directly instead of logging it as an internal failure.
 */
trait GrindsFormInput
{
    /**
     * Grinds $raw_parameters through $description (an Activity's own
     * getInputDescription()) and returns either the resulting content as a
     * Result\Ok - with the exact array shape getInputDescription() itself
     * declares, e.g. ['enabled' => bool] or ['language_key' => string,
     * 'enabled' => bool] - or a Result\Error describing what about
     * $raw_parameters was rejected. Never throws.
     *
     * A validation failure (a required field left blank, an unknown key in
     * a Group, a blank \InvalidArgumentException from the UI framework
     * itself, ...) is reported as a Result\Error carrying an
     * InvalidInputException - a concrete, already user-safe message (see
     * describeInputError() and the two catch blocks below) - never the
     * uninformative, generic "ui_error_in_group" text Group::withInput()
     * itself would otherwise surface. Any other, genuinely unexpected
     * failure (e.g. getInputDescription() itself throwing) is reported as a
     * Result\Error carrying that \Throwable as-is.
     */
    private function grind(FormInput $description, array $raw_parameters): Result
    {
        try {
            $named = $this->nameForGrinding($description);

            $flat_input = [];
            $this->collectRawValues($named, $raw_parameters, $flat_input);

            $with_input = $named->withInput(new ArrayInputData($flat_input));
            $content = $with_input->getContent();

            if ($content->isError()) {
                return new Result\Error($this->describeInputError($with_input));
            }

            return new Result\Ok($content->value());
        } catch (InvalidInputException $e) {
            // Already a SafeToDisplayActivityError - e.g. thrown by
            // collectRawValues() itself for an unknown Group key. Passed
            // through unmodified rather than falling into the next catch
            // below (which would still produce an equivalent, but needlessly
            // re-wrapped, InvalidInputException).
            return new Result\Error($e);
        } catch (\InvalidArgumentException $e) {
            // Input::withValue() (reached via $named->withInput() above) and
            // normalizeCheckboxRawValue() both throw a blank
            // \InvalidArgumentException for invalid raw input - never a
            // SafeToDisplayActivityError itself. Converted into one here so
            // an admin sees this as the field-rejection it is, instead of a
            // generic logged internal error (see class docblock).
            return new Result\Error(new InvalidInputException($e->getMessage(), 0, $e));
        } catch (\Throwable $e) {
            return new Result\Error(
                $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e)
            );
        }
    }

    /**
     * Assigns names to $description and, recursively, every field nested
     * inside it (see class docblock, "Why the downcast..."). A fresh
     * FormInputNameSource is used every time: names only need to be unique
     * WITHIN this one grind() call.
     */
    private function nameForGrinding(FormInput $description): FormInput&InputInternal
    {
        if (!$description instanceof InputInternal) {
            throw new \LogicException(
                static::class . '::getInputDescription() must return a FormInput built from the ' .
                'UI framework (i.e. one that also implements ' . InputInternal::class . ') to be ' .
                'grindable by maybePerformAs() - see the GrindsFormInput trait for why.'
            );
        }

        // withNameFrom() is declared to return `self` on InputInternal (a
        // clone of $description) - see its own docblock for why the type
        // itself does not say so explicitly.
        /** @var FormInput&InputInternal $named */
        $named = $description->withNameFrom(new FormInputNameSource());

        return $named;
    }

    /**
     * Recursively walks $named (an already-named FormInput tree, see
     * nameForGrinding()) in lock step with $raw_value, writing one entry per
     * LEAF field into $flat, keyed by the name just assigned to it - the
     * flat name => value map a single ArrayInputData must carry for
     * Group::withInput()/Input::withInput() to later resolve each field's
     * own value via `$input->getOr($this->getName(), ...)`.
     *
     * A missing raw value defaults to "" for an ordinary field - the same
     * "submitted, but blank" shape a real HTML input would have - rather
     * than null, which every concrete FormInput's isClientSideValueOk()
     * rejects unconditionally regardless of required-ness; required-ness
     * itself is still enforced by the field's own refinery constraint.
     *
     * A NESTED Group's raw value carrying an unknown child key (e.g. an
     * unknown translation language under 'translations') is rejected via
     * InvalidInputException rather than silently ignored. Deliberately NOT
     * enforced for $raw_parameters itself (the outermost Group -
     * $enforce_known_keys stays false on grind()'s first call): several
     * Activities rely on an extra, unrelated top-level key being tolerated
     * (e.g. AddLanguageEntry's reserved 'usr_id', see its class docblock).
     *
     * @param mixed $raw_value
     * @param array<string, mixed> $flat
     */
    private function collectRawValues(Input $named, mixed $raw_value, array &$flat, bool $enforce_known_keys = false): void
    {
        if ($named instanceof GroupInput) {
            $sub_raw = is_array($raw_value) ? $raw_value : [];

            if ($enforce_known_keys) {
                $unknown_keys = array_diff(array_keys($sub_raw), array_keys($named->getInputs()));
                if ($unknown_keys !== []) {
                    // $unknown_keys come straight from the caller-supplied
                    // $raw_parameters (e.g. a REST body) - HTML-escaped
                    // before being embedded, since this message is rendered
                    // unescaped by callers (e.g.
                    // ilObjLanguageFolderGUI::activityErrorMessage()).
                    throw new InvalidInputException(
                        'Unknown key(s) for ' . $this->fieldLabelForErrorMessage($named) . ': '
                        . implode(', ', array_map(
                            static fn(int|string $key): string => htmlspecialchars((string) $key, ENT_QUOTES),
                            $unknown_keys
                        ))
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
     * Turns an array-of-strings raw value for a Text field (e.g.
     * ['de', 'fr']) into the same comma-separated string a real HTML text
     * input would carry (e.g. 'de,fr') - see class docblock, "Array raw
     * values for a Text field".
     *
     * If not every element is a string, the array is passed through
     * unchanged instead - Text::withValue() then rejects it exactly as
     * before (a non-string element is never silently coerced), so this
     * only widens what is *accepted* for genuinely equivalent
     * list-of-strings input, it never weakens validation.
     *
     * @return list<string>|array<mixed>
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

    /**
     * Conservative normalisation of common primitive true/false
     * representations into the raw "checked"/""-convention
     * Checkbox::withInput() itself already expects for real HTML requests
     * (see \ILIAS\UI\Implementation\Component\Input\Field\Checkbox::withInput():
     * `$value === "checked"` decides true, anything else - including an
     * entirely absent value, since unchecked checkboxes are never submitted
     * - decides false). This performs ONLY that upfront translation; the
     * actual true/false decision is still made by Checkbox::withInput()
     * itself, completely unmodified.
     *
     * 'checked' and 'on' are included on purpose, not just as a defensive
     * superset: they are the two raw values a genuine HTML checkbox
     * actually submits (`checked`, since that convention is exactly what
     * Checkbox::withInput() itself expects; `on` as the browser-default
     * `value` attribute for a checkbox that carries none) - invoking
     * maybePerformAs() directly against a raw form POST body is a
     * supported call path, not just the ergonomic string/bool convenience
     * every other whitelist entry below caters for.
     *
     * Deliberately NOT a `(bool)` cast: that would silently turn the
     * strings "false"/"0"/"" (and e.g. "no", "off", ...) into `true`, since
     * every non-empty PHP string except "0" is truthy. Anything not in this
     * explicit whitelist (floats, arrays, objects, or strings other than
     * the ones listed) is rejected outright rather than guessed at -
     * "konservativ normalisieren", not "alles akzeptieren".
     */
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
            . var_export($value, true)
        );
    }

    /**
     * Builds a field-level InvalidInputException out of an already-grinded
     * $with_input tree whose top-level getContent() reported an error -
     * rather than surfacing Group::withInput()'s own top-level replacement
     * text ("ui_error_in_group"), which discards exactly which field(s)
     * actually failed and why.
     */
    private function describeInputError(FormInput $with_input): InvalidInputException
    {
        $field_errors = [];
        $this->collectFieldErrors($with_input, $field_errors);

        if ($field_errors === []) {
            // Should not normally happen: getContent()->isError() implies
            // at least one leaf recorded its own getError(). Kept only so
            // grind() can never silently lose an error that does not fit
            // this shape (e.g. a hypothetical Group-level-only constraint).
            $error = $with_input instanceof InputInternal ? $with_input->getError() : null;
            $field_errors[] = $error ?? 'Invalid input.';
        }

        return new InvalidInputException(implode('; ', $field_errors));
    }

    /**
     * Recurses into $named (see describeInputError()), collecting one
     * "name: error" entry per LEAF field that carries its own getError() -
     * a Group's own getError() (always just the generic "ui_error_in_group"
     * text) is deliberately never added itself, only ever used as the
     * signal to recurse further down into its children.
     *
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

    /**
     * A human-readable identifier for a field, for use in error messages.
     * Prefers the dedicated name every field in this component's Activities
     * is built with (e.g. 'language_keys', 'de', 'translations' - see class
     * docblock, "Why the downcast...") over the opaque, auto-generated name
     * assigned during grinding (see nameForGrinding()), which would only
     * confuse a message meant for an end user.
     */
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
