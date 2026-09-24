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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TranslationMarkupPolicy: the single gate for the HTML a translation value may contain (Konzept
 * "Delta-Overlay + mitgelieferter Stand als Build-Artefakt", Entscheidung 6). Security-critical -
 * this is what stands between a `.po`/local write and stored XSS.
 */
class TranslationMarkupPolicyTest extends TestCase
{
    private TranslationMarkupPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TranslationMarkupPolicy();
    }

    // ------------------------------------------------------------ allowed, byte-identical

    #[DataProvider('allowedValues')]
    public function testAllowedValuesAreByteIdenticalAndHaveNoViolations(string $value): void
    {
        $this->assertTrue($this->policy->isAllowed($value));
        $this->assertSame([], $this->policy->findViolations($value));
        $this->assertSame($value, $this->policy->sanitize($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedValues(): array
    {
        return [
            'plain text' => ['Hallo Welt'],
            'no angle bracket at all' => ['a <= b und c >= d, aber ohne Tag'],
            'less-than followed by a digit is not a tag start' => ['a<3 und b<5, kein Tag'],
            'a with allowed attributes' => ['<a href="https://ilias.de" target="_blank" rel="noopener" title="ILIAS">Link</a>'],
            'relative href' => ['<a href="/goto.php?target=1">Link</a>'],
            'fragment only href' => ['<a href="#anchor">Sprung</a>'],
            'mailto href' => ['<a href="mailto:info@ilias.de">Mail</a>'],
            'img with src and alt' => ['<img src="https://ilias.de/logo.png" alt="Logo">'],
            'relative img src' => ['<img src="/images/logo.png" alt="Logo">'],
            'span with class and style' => ['<span class="hl" style="color:red">wichtig</span>'],
            'div align' => ['<div align="center">Text</div>'],
            'p align' => ['<p align="right">Text</p>'],
            'br self-closing stays as written' => ['Zeile 1<br/>Zeile 2'],
            'br plain' => ['Zeile 1<br>Zeile 2'],
            'nested formatting' => ['<b><i>fett kursiv</i></b>'],
            'list' => ['<ul><li>eins</li><li>zwei</li></ul>'],
            'ordered list' => ['<ol><li>eins</li></ol>'],
            'placeholder in href is a relative URL' => ['<a href="%1$s">Platzhalter</a>'],
            'gap tag without attributes' => ['<gap>'],
            'h3 heading' => ['<h3>Überschrift</h3>'],
            'sub and sup' => ['H<sub>2</sub>O und x<sup>2</sup>'],
            'code and pre' => ['<pre><code>echo 1;</code></pre>'],
        ];
    }

    // ------------------------------------------------------------ disallowed tags/attributes

    #[DataProvider('violatingValues')]
    public function testViolatingValuesAreDetected(string $value, string $expected_violation_substring): void
    {
        $this->assertFalse($this->policy->isAllowed($value));
        $violations = $this->policy->findViolations($value);
        $this->assertNotEmpty($violations);
        $found = false;
        foreach ($violations as $violation) {
            if (str_contains($violation, $expected_violation_substring)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, sprintf(
            'Expected a violation containing "%s", got: %s',
            $expected_violation_substring,
            implode('; ', $violations)
        ));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function violatingValues(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>', 'tag <script>'],
            'onerror on img' => ['<img src="x.png" alt="x" onerror="alert(1)">', 'attribute "onerror"'],
            'onclick on a' => ['<a href="/x" onclick="alert(1)">x</a>', 'attribute "onclick"'],
            'javascript scheme in href' => ['<a href="javascript:alert(1)">x</a>', 'URL "javascript:alert(1)"'],
            'javascript scheme uppercase' => ['<a href="JavaScript:alert(1)">x</a>', 'URL "JavaScript:alert(1)"'],
            'javascript scheme with a tab' => ["<a href=\"java\tscript:alert(1)\">x</a>", 'URL'],
            'javascript scheme with a newline' => ["<a href=\"java\nscript:alert(1)\">x</a>", 'URL'],
            'javascript scheme via entity' => ['<a href="&#106;avascript:alert(1)">x</a>', 'URL "javascript:alert(1)"'],
            'data scheme in img src' => ['<img src="data:text/html,<script>alert(1)</script>" alt="x">', 'URL "data:'],
            'body onload' => ['<body onload="alert(1)">x</body>', 'attribute "onload"'],
            'html attribute' => ['<html onmouseover="alert(1)">x</html>', 'attribute "onmouseover"'],
            // <td>/<tr>/<tbody> are not in ALLOWED_ATTRIBUTES at all - the table-context parse (which
            // alone turns a bare <td> into an element) reports the tag itself as the violation
            'td is not an allowed tag at all' => ['<td onclick="alert(1)">x</td>', 'tag <td>'],
            'comment' => ['<!-- comment -->', 'comment'],
            'broken closing tag becomes a comment' => ['</ i>', 'comment'],
            'unknown tag' => ['<blink>x</blink>', 'tag <blink>'],
            'disallowed attribute on allowed tag' => ['<b class="x">fett</b>', 'attribute "class" of <b>'],
            'style on unstyled tag' => ['<p style="color:red">x</p>', 'attribute "style" of <p>'],
            // Round 1 fix: a value ending inside an unfinished tag - harmless in isolation, but a
            // browser completes it with whatever markup follows the value on the real page
            'unfinished img tag with a dangling attribute at the end' => ['<img src=x onerror=alert(1)// ', 'incomplete tag at the end'],
            'unfinished a tag with an unterminated quoted attribute' => ["<a href='javascript:alert(1)'", 'incomplete tag at the end'],
            'unfinished img self-closing slash without the closing ">"' => ['<img src="x" alt="y"/', 'incomplete tag at the end'],
            // Round 1 fix: a "<" inside an attribute value is harmless as an attribute, but the SAME
            // value is also inserted into raw-text/script contexts elsewhere on the page, where it
            // would start real markup (e.g. break out of a following <title>/<textarea>/<script>)
            '"<" inside a class attribute value (breaks out of a following <title>)' => [
                '<span class="</title><img src=x onerror=alert(1)>">Portfolio</span>',
                '"<" in attribute "class" of <span>',
            ],
            '"<" inside a class attribute value (breaks out of a following inline <script>)' => [
                '<span class="<!--<script>">x</span>',
                '"<" in attribute "class" of <span>',
            ],
        ];
    }

    /**
     * A value that already legitimately contains the sentinel character hasIncompleteTagAtEnd() uses
     * internally (U+E000, a private-use character no real translation needs) must not desynchronise
     * the sentinel count it relies on - a complete, otherwise allowed tag stays allowed.
     */
    public function testAValueContainingTheInternalSentinelCharacterIsStillHandledCorrectly(): void
    {
        $value = "<b>text\u{E000}more</b>";

        $this->assertTrue($this->policy->isAllowed($value));
        $this->assertSame($value, $this->policy->sanitize($value));
    }

    /**
     * The same sentinel character, but now genuinely inside an unfinished tag at the end - still
     * correctly detected despite the extra, user-supplied occurrences the count must add on top of.
     */
    public function testASentinelCharacterInTheValueDoesNotHideAGenuinelyIncompleteTagAtTheEnd(): void
    {
        $value = "\u{E000}<img src=x onerror=alert(1)//";

        $this->assertFalse($this->policy->isAllowed($value));
        $this->assertContains('incomplete tag at the end', $this->policy->findViolations($value));
    }

    // ------------------------------------------------------------ trailing "<" (concatenation XSS)

    /**
     * Nachtrag: a value ending in a bare "<" is harmless on its own, but a second, independently
     * allowed value concatenated directly after it ("Weiter <" . "img src=x onerror=alert(1)>", see
     * scratchpad/poc_concat.php) would together form real markup on the page. Rejecting a trailing
     * "<" closes that concatenation gap.
     */
    public function testAValueEndingInABareLessThanIsAViolation(): void
    {
        $value = 'Weiter <';

        $this->assertFalse($this->policy->isAllowed($value));
        $this->assertContains('incomplete tag at the end', $this->policy->findViolations($value));
    }

    /**
     * sanitize() must not just drop the trailing "<" silently: htmlspecialchars() (the final,
     * text-only fallback for a value that stays unresolvable after MAX_SANITIZE_ROUNDS) turns it into
     * "&lt;", which is safe under concatenation and preserves the visible character.
     */
    public function testSanitizeEscapesATrailingLessThanInsteadOfDroppingIt(): void
    {
        $this->assertSame('Weiter &lt;', $this->policy->sanitize('Weiter <'));
    }

    /**
     * A "<" that is NOT the last character of the value - followed by whitespace, a digit, or "="
     * comparison text - is still plain text to the parser and stays allowed byte-identical, exactly
     * as before this fix. Only a value ending in "<" is rejected.
     */
    #[DataProvider('lessThanNotAtTheEndStaysAllowed')]
    public function testALessThanNotAtTheVeryEndOfTheValueStaysAllowed(string $value): void
    {
        $this->assertTrue($this->policy->isAllowed($value));
        $this->assertSame($value, $this->policy->sanitize($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lessThanNotAtTheEndStaysAllowed(): array
    {
        return [
            'less-than followed by a space' => ['x < '],
            'comparison text' => ['a <= b'],
            'less-than followed by a digit' => ['a<3 und b<5, kein Tag'],
        ];
    }

    public function testACaseInsensitiveAttributeNameIsStillCaughtAsAViolation(): void
    {
        $this->assertFalse($this->policy->isAllowed('<img src="x.png" alt="x" OnError="alert(1)">'));
    }

    public function testATableContextCatchesATagAllowedOnlyOutsideOfIt(): void
    {
        // a bare <img onclick> is invisible to the body parse alone if libxml drops the attribute
        // differently there than in a table context - both parses must be consulted
        $this->assertFalse($this->policy->isAllowed('<tr><td><img src="x.png" alt="x" onclick="alert(1)"></td></tr>'));
    }

    // ------------------------------------------------------------ sanitize()

    public function testSanitizeKeepsTextOfARemovedScriptTag(): void
    {
        $result = $this->policy->sanitize('vor<script>bad()</script>her');

        $this->assertTrue($this->policy->isAllowed($result));
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringContainsString('vor', $result);
        $this->assertStringContainsString('her', $result);
    }

    public function testSanitizeRemovesADisallowedAttributeButKeepsTheTag(): void
    {
        $result = $this->policy->sanitize('<img src="x.png" alt="x" onerror="alert(1)">');

        $this->assertTrue($this->policy->isAllowed($result));
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringContainsString('src="x.png"', $result);
    }

    public function testSanitizeRemovesAComment(): void
    {
        $result = $this->policy->sanitize('vor<!-- geheim -->her');

        $this->assertTrue($this->policy->isAllowed($result));
        $this->assertStringNotContainsString('geheim', $result);
    }

    public function testSanitizeOfAnAlreadyAllowedValueNeverParsesTheValue(): void
    {
        // isAllowed()/sanitize() must short-circuit before touching the parser for such input
        $this->assertSame('a <= b', $this->policy->sanitize('a <= b'));
    }

    public function testSanitizeOfATemplateTagRemovesItWithItsContent(): void
    {
        $result = $this->policy->sanitize('vor<template><script>bad()</script></template>her');

        $this->assertTrue($this->policy->isAllowed($result));
        $this->assertStringNotContainsString('bad()', $result);
        $this->assertSame('vorher', trim($result));
    }

    // ------------------------------------------------------------ URL scheme edge cases

    #[DataProvider('allowedHrefUrls')]
    public function testAllowedHrefUrlSchemes(string $url): void
    {
        $this->assertTrue($this->policy->isAllowed(sprintf('<a href="%s">x</a>', $url)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedHrefUrls(): array
    {
        return [
            'http' => ['http://ilias.de'],
            'https' => ['https://ilias.de'],
            'mailto' => ['mailto:info@ilias.de'],
            'relative path' => ['/goto.php'],
            'relative with query' => ['goto.php?target=1'],
            'fragment' => ['#top'],
            'placeholder' => ['%1$s'],
        ];
    }

    #[DataProvider('disallowedSrcUrls')]
    public function testMailtoIsNotAllowedForImgSrc(string $url): void
    {
        $this->assertFalse($this->policy->isAllowed(sprintf('<img src="%s" alt="x">', $url)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function disallowedSrcUrls(): array
    {
        return [
            'mailto is only allowed for href, not src' => ['mailto:info@ilias.de'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,x'],
        ];
    }

    // ------------------------------------------------------------ findInvalidValues()

    /**
     * The single place a local write path (admin edit, import, customizing file, "add new variable")
     * checks before writing anything at all: only the invalid entries are returned, keyed by their
     * original key, in the order $values was iterated - never the values themselves (see
     * ilLanguageInvalidMarkupException, which reports only keys).
     */
    public function testFindInvalidValuesReturnsOnlyTheInvalidEntriesInInputOrder(): void
    {
        $result = (new TranslationMarkupPolicy())->findInvalidValues([
            'greeting' => 'Hallo',
            'bad_one' => '<script>alert(1)</script>',
            'farewell' => 'Tschüss',
            'bad_two' => '<img src=x onerror=alert(2)>',
        ]);

        $this->assertSame(['bad_one', 'bad_two'], array_keys($result));
        $this->assertContains('tag <script>', $result['bad_one']);
        $this->assertNotEmpty($result['bad_two']);
    }

    /**
     * Empty when every value is allowed - including the trivial case of no values at all.
     */
    public function testFindInvalidValuesIsEmptyWhenEverythingIsAllowed(): void
    {
        $policy = new TranslationMarkupPolicy();

        $this->assertSame([], $policy->findInvalidValues(['greeting' => 'Hallo', 'farewell' => '<b>Tschüss</b>']));
        $this->assertSame([], $policy->findInvalidValues([]));
    }

    /**
     * An int key (e.g. a numeric language-entry topic, or a plain list) is not silently dropped or
     * skipped - it shows up in the result (as PHP itself always normalises a numeric string/int array
     * key to int, findInvalidValues() casting it via `(string) $key` still round-trips to the same
     * int key once assigned - the callers that only ever implode()/print the key, e.g.
     * ilLanguageInvalidMarkupException, get an equivalent string regardless).
     */
    public function testFindInvalidValuesDoesNotDropAnIntegerKey(): void
    {
        $result = (new TranslationMarkupPolicy())->findInvalidValues([42 => '<script>alert(1)</script>']);

        $this->assertSame([42], array_keys($result));
        $this->assertSame('42', implode(',', array_keys($result)));
    }

    /**
     * A non-string value (e.g. a bool/int/null slipping through from an untyped array) is cast to
     * string before the check, exactly like findViolations() itself expects.
     */
    public function testFindInvalidValuesCastsNonStringValuesToStringBeforeChecking(): void
    {
        $result = (new TranslationMarkupPolicy())->findInvalidValues(['flag' => false, 'greeting' => 'Hallo']);

        // false -> "" -> allowed (no tag at all)
        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------ findInvalidChangedValues()

    /**
     * The core of Konzept-Schritt-4's rule: a value is only checked at all when this write actually
     * CHANGES it - i.e. it differs from both the current value and the shipped one. An unmodified
     * value with disallowed markup that was already shipped that way (e.g. a historical `<br \>` in a
     * shipped `.po`/`.lang`) must never block a save or import merely because the whole file/form is
     * submitted again - only a value someone actually edited (and that then still violates the policy)
     * is rejected.
     */
    /**
     * @param array<string, string> $values
     * @param array<string, string> $current
     * @param array<string, string> $shipped
     * @param list<string> $expected_invalid_keys
     */
    #[DataProvider('findInvalidChangedValuesCases')]
    public function testFindInvalidChangedValues(array $values, array $current, array $shipped, array $expected_invalid_keys): void
    {
        $result = (new TranslationMarkupPolicy())->findInvalidChangedValues($values, $current, $shipped);

        $this->assertSame($expected_invalid_keys, array_keys($result));
    }

    /**
     * @return array<string, array{0: array<string,string>, 1: array<string,string>, 2: array<string,string>, 3: list<string>}>
     */
    public static function findInvalidChangedValuesCases(): array
    {
        $bad = '<script>alert(1)</script>';

        return [
            'unmodified value equal to the shipped one, even with disallowed markup, is not checked' => [
                ['key' => $bad], ['key' => 'irrelevant, current differs too'], ['key' => $bad], [],
            ],
            'unmodified value equal to the current one, even with disallowed markup, is not checked' => [
                ['key' => $bad], ['key' => $bad], ['key' => 'unrelated shipped value'], [],
            ],
            'a genuinely changed value with disallowed markup is rejected' => [
                ['key' => $bad], ['key' => 'Hallo'], ['key' => 'Servus'], ['key'],
            ],
            'a genuinely changed but allowed value is never in the result' => [
                ['key' => '<b>Hallo</b>'], ['key' => 'Hallo'], ['key' => 'Servus'], [],
            ],
            'a key not present in current or shipped at all (a new entry) is checked' => [
                ['key' => $bad], [], [], ['key'],
            ],
            'export-then-reimport unchanged: same as both current and shipped, byte for byte' => [
                ['key' => $bad], ['key' => $bad], ['key' => $bad], [],
            ],
        ];
    }

    public function testFindInvalidChangedValuesReturnsTheSameViolationsAsFindInvalidValues(): void
    {
        $policy = new TranslationMarkupPolicy();
        $bad = '<img src=x onerror=alert(1)>';

        $changed = $policy->findInvalidChangedValues(['key' => $bad], ['key' => 'Hallo'], []);
        $plain = $policy->findInvalidValues(['key' => $bad]);

        $this->assertSame($plain, $changed);
    }
}
