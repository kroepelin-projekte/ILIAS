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

use ILIAS\Language\Tests\Activities\RealFieldsUiFactory;
use ILIAS\Language\Tests\Activities\GrindsFormInputTestHost;
use ILIAS\Language\Activities\InvalidInputException;
use ILIAS\Language\Activities\SafeToDisplayActivityError;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the GrindsFormInput trait itself (see its own class
 * docblock), in isolation from any concrete Activity - via
 * GrindsFormInputTestHost, a minimal class that only mixes in the trait and
 * exposes its private grind() through a public wrapper.
 *
 * These tests are deliberately about the trait's OWN contract, not about
 * any one Activity's business rules - the Activity-level contract tests
 * (an array-of-strings raw value for 'language_keys' actually reaching
 * perform() correctly) live in InstallLanguageTest/UninstallLanguageTest/
 * UpdateLanguageTest/RemoveLocalLanguageChangesTest instead.
 */
class GrindsFormInputTest extends TestCase
{
    use RealFieldsUiFactory;

    private function host(): GrindsFormInputTestHost
    {
        return new GrindsFormInputTestHost();
    }

    /**
     * getInputDescription() is declared to return the public FormInput
     * interface - grind() must not blindly trust that a caller's
     * implementation also satisfies the internal InputInternal interface
     * it actually needs (see the trait's own class docblock, "Why the
     * downcast ... is necessary and safe"). A FormInput that does NOT
     * implement InputInternal must be reported as a clean Result\Error,
     * never a fatal TypeError/uncaught exception.
     */
    public function testGrindReturnsResultErrorInsteadOfCrashingWhenDescriptionIsNotInputInternal(): void
    {
        $description = $this->createMock(FormInput::class);

        $result = $this->host()->callGrind($description, []);

        $this->assertTrue($result->isError());
        $this->assertInstanceOf(\LogicException::class, $result->error());
    }

    public static function checkboxWhitelistAcceptsRawValueProvider(): array
    {
        return [
            'bool true' => [true, true],
            'int 1' => [1, true],
            'string "1"' => ['1', true],
            'string "true"' => ['true', true],
            'string "checked"' => ['checked', true],
            'string "on"' => ['on', true],
            'bool false' => [false, false],
            'int 0' => [0, false],
            'string "0"' => ['0', false],
            'string "false"' => ['false', false],
            'empty string' => ['', false],
            'null (field omitted entirely)' => [null, false],
        ];
    }

    /**
     * Every raw value normalizeCheckboxRawValue() whitelists (including
     * 'checked'/'on', the two raw values a genuine HTML checkbox actually
     * submits - see the method's own docblock) must grind through to the
     * correct strict bool via the REAL Checkbox field, not a mock.
     */
    #[DataProvider('checkboxWhitelistAcceptsRawValueProvider')]
    public function testGrindNormalizesEveryWhitelistedCheckboxRawValue(mixed $raw_value, bool $expected): void
    {
        $description = $this->checkboxGroupDescription();

        $raw_parameters = $raw_value === null ? [] : ['enabled' => $raw_value];

        $result = $this->host()->callGrind($description, $raw_parameters);

        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->value()['enabled']);
    }

    /**
     * Every field this trait grinds is, in every real Activity, nested
     * inside a top-level Group (see e.g. SetLanguageTranslationEnabled::
     * getInputDescription()) - collectRawValues() only narrows $raw_value
     * down to one particular key (here 'enabled') when recursing INTO a
     * Group's children, so a bare top-level Checkbox/Text field (with no
     * enclosing Group) would incorrectly receive the *entire*
     * $raw_parameters array as its own raw value instead. This helper
     * mirrors the real shape every Activity actually uses.
     */
    private function checkboxGroupDescription(): FormInput
    {
        $checkbox = $this->createRealFieldsUiFactory()->input()->field()->checkbox('Enabled', '')
            ->withDedicatedName('enabled');

        return $this->createRealFieldsUiFactory()->input()->field()->group(['enabled' => $checkbox]);
    }

    public static function checkboxWhitelistRejectsRawValueProvider(): array
    {
        return [
            'array' => [[true]],
            'float' => [1.0],
            'unrecognized string' => ['maybe'],
        ];
    }

    /**
     * Anything outside the explicit whitelist must be rejected outright
     * ("konservativ normalisieren", not "alles akzeptieren" - see the
     * method's own docblock) rather than being guessed at - grind() must
     * turn the resulting \InvalidArgumentException into a Result\Error,
     * never let it propagate uncaught.
     *
     * Regression test: grind()'s dedicated `catch (\InvalidArgumentException $e)`
     * block (placed BEFORE the generic `catch (\Throwable $e)`) must convert
     * this into an InvalidInputException (a SafeToDisplayActivityError) -
     * NOT a raw \InvalidArgumentException, and NOT a generic
     * \RuntimeException wrapper. A caller (e.g.
     * RendersActivityErrors::activityErrorMessage()) relies on this to show
     * the rejection to the end user directly instead of logging it as an
     * internal failure.
     */
    #[DataProvider('checkboxWhitelistRejectsRawValueProvider')]
    public function testGrindRejectsRawValueOutsideTheCheckboxWhitelist(mixed $raw_value): void
    {
        $result = $this->host()->callGrind($this->checkboxGroupDescription(), ['enabled' => $raw_value]);

        $this->assertTrue($result->isError());
        $error = $result->error();
        $this->assertInstanceOf(InvalidInputException::class, $error);
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $error);
    }

    /**
     * The same conversion (\InvalidArgumentException -> InvalidInputException),
     * but reached via the UI framework's own `Input::withValue()`/`checkArg()`
     * (not normalizeCheckboxRawValue()): a non-string, non-array raw value
     * for a Text field fails Text::isClientSideValueOk()'s own type check,
     * which throws a blank \InvalidArgumentException ("Display value does
     * not match input type.") - grind() must convert this one too, not just
     * the Checkbox-specific one above.
     */
    public function testGrindConvertsAUiFrameworkInvalidArgumentExceptionFromANonStringTextValueIntoInvalidInputException(): void
    {
        $text = $this->createRealFieldsUiFactory()->input()->field()->text('Language key', '')
            ->withDedicatedName('language_key');
        $description = $this->createRealFieldsUiFactory()->input()->field()->group([
            'language_key' => $text,
        ]);

        // A bool is neither a string nor an array - collectRawValues()
        // passes it straight through unmodified (only array/Checkbox raw
        // values are special-cased), so it reaches Text::withValue() as-is.
        $result = $this->host()->callGrind($description, ['language_key' => true]);

        $this->assertTrue($result->isError());
        $error = $result->error();
        $this->assertInstanceOf(InvalidInputException::class, $error);
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $error);
    }

    /**
     * A validation failure on a named field must be reported as an
     * InvalidInputException whose message identifies the field (via its
     * dedicated name) and the underlying InputInternal::getError() code -
     * "<field>: <error>" - rather than the generic, uninformative
     * "ui_error_in_group" text Group::withInput() would otherwise surface
     * (see describeInputError()'s own docblock).
     */
    public function testGrindReportsAFieldAttributedInvalidInputExceptionOnValidationFailure(): void
    {
        $language_key = $this->createRealFieldsUiFactory()->input()->field()->text('Language key', '')
            ->withRequired(true)
            ->withDedicatedName('language_key');
        $description = $this->createRealFieldsUiFactory()->input()->field()->group([
            'language_key' => $language_key,
        ]);

        // Blank/missing raw value for a required field - fails its own
        // hasMinLength(1) constraint inside grind() itself.
        $result = $this->host()->callGrind($description, []);

        $this->assertTrue($result->isError());
        $error = $result->error();
        $this->assertInstanceOf(InvalidInputException::class, $error);
        $this->assertInstanceOf(SafeToDisplayActivityError::class, $error);
        $this->assertStringStartsWith('language_key: ', $error->getMessage());
        $this->assertNotSame('language_key: ', $error->getMessage());
    }

    /**
     * A raw array-of-strings value for a Text field (e.g. the exact shape
     * class.ilObjLanguageFolderGUI.php sends for 'language_keys') must be
     * joined into the same comma-separated string a real HTML text input
     * would carry (see joinListOfStringsRawValue()'s own docblock) BEFORE
     * it reaches the field - covered end-to-end (through a real Activity,
     * not this isolated host) by the *AcceptsAnArrayOfLanguageKeys*
     * contract tests in InstallLanguageTest/UninstallLanguageTest/
     * UpdateLanguageTest/RemoveLocalLanguageChangesTest; this is the
     * trait-level counterpart pinning the join itself.
     */
    public function testGrindJoinsAnArrayOfStringsRawValueForATextFieldIntoACommaSeparatedString(): void
    {
        $language_keys = $this->createRealFieldsUiFactory()->input()->field()->text('Language keys', '')
            ->withDedicatedName('language_keys');
        $description = $this->createRealFieldsUiFactory()->input()->field()->group([
            'language_keys' => $language_keys,
        ]);

        $result = $this->host()->callGrind($description, ['language_keys' => ['de', 'fr']]);

        $this->assertTrue($result->isOk());
        $this->assertSame('de,fr', $result->value()['language_keys']);
    }
}
