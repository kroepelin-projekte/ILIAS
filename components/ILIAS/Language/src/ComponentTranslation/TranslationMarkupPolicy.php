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

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * The HTML a translation value may contain - the one place that decides it, for the shipped files
 * (compiled by Setup's build, see ShippedTranslations: cleaned there, with a warning) and for every
 * local write path (rejected there, see findInvalidValues()).
 *
 * Allowed are the tags listed in ALLOWED_ATTRIBUTES, each only with the attributes listed there
 * (attribute names case-insensitive), and `href`/`src` only with a URL that is relative (including
 * `#...`) or uses one of the schemes listed in URL_SCHEMES, and `style` only with declarations of
 * the properties listed in STYLE_PROPERTIES whose values use no function but those in
 * STYLE_FUNCTIONS (no `url(`, `expression(`, ...), no CSS escapes and no comments. An attribute value
 * must not contain "<". Comments, a value ending inside an unfinished tag, and unbalanced markup
 * count as a violation, too: markup that leaves an element open (unless HTML5 allows to omit its end
 * tag, e.g. for `<li>` or `<p>`) or closes an element around the value in the page it is inserted
 * into (see collectUnbalancedMarkup()).
 * Text that is no tag to an HTML parser - `a <= b`, `<<` - stays allowed.
 *
 * A value with a "<" longer than MAX_CHECKED_LENGTH bytes is not parsed and never allowed.
 *
 * A value is checked, never rewritten: an allowed value is returned byte-identical by sanitize(),
 * no normalisation of `<br>`, entities or quoting takes place. Only a value with violations is
 * rebuilt, and only then its markup may change.
 *
 * The value is parsed by PHP's HTML5-conformant parser (ext-dom, Dom\HTMLDocument), i.e. the way a
 * browser parses it - no regular expressions. An HTML5 parser ignores some start tags depending on
 * where the markup ends up (a `<td>` outside of a table, the attributes of a `<body>` are moved to
 * the page's body instead), and translations are inserted in many places. The value is therefore
 * parsed twice: as the content of a document body (catches `<body>`/`<html>` attributes) and as the
 * content of a table (where every other start tag, including the table parts ignored in a body,
 * becomes an element). What either parse shows counts. Start tags ignored in both - `<head>`,
 * `<frame>`, `<frameset>` after content, `<!DOCTYPE>` - are ignored by a browser inside a page as
 * well and stay in an allowed value. The balance of the markup is checked by a third parse, inside
 * a chain of surrounding elements (see collectUnbalancedMarkup()). Content inside
 * raw-text contexts (`<textarea>`, `<script>`, ...) is text to a browser and therefore to this
 * check, too - the enclosing tag itself is a violation anyway.
 *
 * Every walk over a parsed tree is iterative, and every parsed tree belongs to a document (see
 * parseInto()): a value nested arbitrarily deep must not exhaust the call stack.
 *
 * HTMLPurifier (vendor/ezyang) is deliberately not used: it always re-serialises its input, so an
 * allowed value could not be kept byte-identical, and its own lexer (DirectLex) does not tokenise
 * like a browser (e.g. a `>` inside a quoted attribute value).
 */
final class TranslationMarkupPolicy
{
    /**
     * tag => allowed attributes (lowercase)
     */
    private const array ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel', 'title'],
        'b' => [],
        'bdo' => [],
        'br' => [],
        'code' => [],
        'div' => ['align'],
        'em' => [],
        'gap' => [],
        'h3' => [],
        'i' => [],
        'img' => ['src', 'alt'],
        'li' => [],
        'ol' => [],
        'p' => ['align'],
        'pre' => [],
        's' => [],
        'small' => [],
        'span' => ['class', 'style'],
        'strike' => [],
        'strong' => [],
        'sub' => [],
        'sup' => [],
        'u' => [],
        'ul' => [],
    ];

    /**
     * URL attribute => schemes it may use besides a relative URL
     */
    private const array URL_SCHEMES = [
        'href' => ['http', 'https', 'mailto'],
        'src' => ['http', 'https'],
    ];

    /**
     * CSS properties a `style` attribute may set (lowercase)
     */
    private const array STYLE_PROPERTIES = [
        'background-color',
        'color',
        'font-style',
        'font-weight',
        'text-align',
        'text-decoration',
    ];

    /**
     * CSS functions a value in a `style` attribute may use (lowercase), for colours
     */
    private const array STYLE_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla'];

    /**
     * Elements whose end tag HTML5 allows to omit ("optional tags"): one of them left open by a value
     * is valid HTML and no imbalance (see collectUnbalancedMarkup())
     */
    private const array OPTIONAL_END_TAGS = [
        'body', 'caption', 'colgroup', 'dd', 'dt', 'head', 'html', 'li', 'optgroup', 'option', 'p', 'rp', 'rt',
        'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
    ];

    /**
     * The elements collectUnbalancedMarkup() puts around a value, outermost first: an end tag in the
     * value that closes one of them would close that element of the page. The table and block
     * elements come first, then the special `<pre>` (a bare `<li>` in the value stops there instead
     * of closing the surrounding `<li>`, as in a page), then the inline elements. Not included:
     * `<p>` (every block start tag of the value would close it) and `<a>` (every link of the value
     * would close it) - a stray `</p>` or `</a>` is not detected. End tags of tags that are not
     * allowed at all (`</form>`, `</section>`, ...) are reported by collectEndTagsOfDisallowedTags().
     */
    private const array SURROUNDING_ELEMENTS = [
        'table', 'tbody', 'tr', 'td', 'div', 'ol', 'li', 'ul', 'li', 'h3', 'pre',
        'span', 'b', 'i', 'u', 's', 'strike', 'strong', 'em', 'small', 'sub', 'sup', 'code', 'bdo', 'gap',
    ];

    /**
     * Marks the elements collectUnbalancedMarkup() puts around a value with their index in
     * SURROUNDING_ELEMENTS (an attribute no allowed value can carry), and the element it puts after
     * the value (a tag no allowed value contains)
     */
    private const string SURROUNDING_MARK = 'data-il-surrounding';
    private const string END_MARK_TAG = 'il-value-end';

    /**
     * White space in a `style` attribute value and between the parts of a tag
     */
    private const string CSS_WHITESPACE = "\t\n\f\r ";

    private const string HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /**
     * How often sanitize() rebuilds a value until the result is allowed - re-parsing serialised
     * markup can in theory produce new markup ("mutation"); if it still does after these rounds, only
     * the text is kept.
     */
    private const int MAX_SANITIZE_ROUNDS = 3;

    /**
     * The longest value (in bytes) with a "<" that is parsed at all. Parsing deeply nested markup
     * takes time quadratic in its length (e.g. 200000 nested `<div>` take minutes), so a longer
     * value with a "<" is a violation without being parsed, and sanitize() escapes it completely.
     * The longest shipped value has about 3 KB.
     */
    private const int MAX_CHECKED_LENGTH = 16384;

    /**
     * Appended to a value to detect markup left open at its end (see hasIncompleteTagAtEnd()): a
     * private-use character, which no translation needs.
     */
    private const string SENTINEL = "\u{E000}";

    /**
     * What is not allowed in $value, without duplicates, e.g. `tag <script>`, `attribute "onclick" of
     * <img>`, `URL "javascript:..." in "href" of <a>` or `comment " i"` (what a browser makes of
     * `</ i>`). Empty if $value is allowed.
     *
     * @return list<string>
     */
    public function findViolations(string $value): array
    {
        // Without a "<" there is neither a tag nor a comment - the parser is not needed
        if (!str_contains($value, '<')) {
            return [];
        }

        if (strlen($value) > self::MAX_CHECKED_LENGTH) {
            return [sprintf('value too long for the markup check (> %d bytes)', self::MAX_CHECKED_LENGTH)];
        }

        $violations = [];
        $this->collectFromBodyParse($value, $violations);
        $this->collectFromTableParse($value, $violations);
        if ($this->hasIncompleteTagAtEnd($value)) {
            $violations['incomplete tag at the end'] = true;
        }
        $this->collectUnbalancedMarkup($value, $violations);
        $this->collectEndTagsOfDisallowedTags($value, $violations);

        return array_keys($violations);
    }

    public function isAllowed(string $value): bool
    {
        return $this->findViolations($value) === [];
    }

    /**
     * findInvalidValues() for the values of $values a local write path actually changes: only a
     * value that differs from its current value ($current, e.g. the database/overlay content) AND
     * from its shipped value ($shipped, the shipped .lang/.po) is checked. An unchanged or shipped
     * value is not a new local value - rejecting it would make every page of a language whose
     * shipped texts break the rules impossible to save, and every export impossible to import again.
     *
     * @param iterable<string|int, string> $values key => value
     * @param array<string|int, string> $current key => current value
     * @param array<string|int, string> $shipped key => shipped value
     * @return array<string, list<string>> see findInvalidValues()
     */
    public function findInvalidChangedValues(iterable $values, array $current, array $shipped): array
    {
        $changed = [];
        foreach ($values as $key => $value) {
            $value = (string) $value;
            if (
                (!array_key_exists($key, $current) || (string) $current[$key] !== $value)
                && (!array_key_exists($key, $shipped) || (string) $shipped[$key] !== $value)
            ) {
                $changed[$key] = $value;
            }
        }

        return $this->findInvalidValues($changed);
    }

    /**
     * The values of $values that are not allowed, with their violations - what a local write path
     * (admin edit, import, customizing file, "add new variable") checks before writing anything, so
     * it can reject all of them at once.
     *
     * @param iterable<string|int, string> $values key => value
     * @return array<string, list<string>> key => violations, in the order of $values; empty if every
     *         value is allowed
     */
    public function findInvalidValues(iterable $values): array
    {
        $invalid = [];
        foreach ($values as $key => $value) {
            $violations = $this->findViolations((string) $value);
            if ($violations !== []) {
                $invalid[(string) $key] = $violations;
            }
        }

        return $invalid;
    }

    /**
     * $value itself (byte-identical) if it is allowed. Otherwise $value without everything that is
     * not allowed: disallowed tags are removed but their text content stays (a `<template>` is
     * removed with its content, a browser never shows that), disallowed attributes and comments are
     * removed. The markup of such a value is re-serialised, so its allowed parts may change in form
     * (e.g. `<br/>` becomes `<br>`, entities become characters), never in meaning. A value too long
     * to be parsed (see MAX_CHECKED_LENGTH) is escaped completely instead: shown as text, with its
     * markup visible.
     */
    public function sanitize(string $value): string
    {
        if ($this->isAllowed($value)) {
            return $value;
        }
        // Not parsed (see MAX_CHECKED_LENGTH): every character stays visible as text, no markup
        // survives - escaping the value is the only result that needs no parse to be safe
        if (strlen($value) > self::MAX_CHECKED_LENGTH) {
            return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $current = $value;
        for ($round = 0; $round < self::MAX_SANITIZE_ROUNDS; $round++) {
            $current = $this->rebuild($current);
            if ($this->isAllowed($current)) {
                return $current;
            }
        }

        return htmlspecialchars($this->parseIntoDiv($current)->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, true> $violations
     */
    private function collectFromBodyParse(string $value, array &$violations): void
    {
        // The wrapper adds no attributes, so every attribute found on <html>, <head> or <body> comes
        // from $value (an HTML5 parser merges a <body ...>/<html ...> tag's attributes into them)
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head></head><body>' . $value,
            LIBXML_NOERROR,
            'UTF-8'
        );
        $root = $document->documentElement;
        if ($root === null) {
            return;
        }
        $this->collectStructuralAttributes($root, $violations);

        $seen_head = false;
        $seen_body = false;
        foreach ($root->childNodes as $child) {
            if ($child instanceof Element && $child->localName === 'head' && !$seen_head) {
                $seen_head = true;
                $this->collectStructuralAttributes($child, $violations);
                $this->collectFromChildren($child, $violations);
                continue;
            }
            if ($child instanceof Element && $child->localName === 'body' && !$seen_body) {
                $seen_body = true;
                $this->collectStructuralAttributes($child, $violations);
                $this->collectFromChildren($child, $violations);
                continue;
            }
            // anything the parser placed next to <head>/<body>
            $this->collectFromNode($child, $violations);
        }
    }

    /**
     * Whether $value ends inside a tag, e.g. `<img src=x onerror=alert(1)// ` or
     * `<a href='javascript:...'`. The parser drops such an unfinished tag at the end of the input,
     * but in a page the markup following the value completes it. Detected by appending SENTINEL for
     * both parse contexts: unless the value ends inside a tag (or a comment, reported anyway), the
     * sentinel ends up as text.
     */
    private function hasIncompleteTagAtEnd(string $value): bool
    {
        // A trailing "<" is text on its own, but becomes a tag as soon as a caller appends another
        // value directly ("Weiter <" . "img src=x onerror=...>"), which on its own is plain text
        if (str_ends_with($value, '<')) {
            return true;
        }

        $expected = substr_count($value, self::SENTINEL) + 1;

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head></head><body>' . $value . self::SENTINEL,
            LIBXML_NOERROR,
            'UTF-8'
        );
        if ($document->documentElement === null || $this->countSentinelsInText($document->documentElement) < $expected) {
            return true;
        }

        $table = $this->parseInto('table', $value . self::SENTINEL);

        return $this->countSentinelsInText($table) < $expected;
    }

    /**
     * Unbalanced markup in $value, the way a browser parses it: $value is parsed inside the chain of
     * SURROUNDING_ELEMENTS (each marked with SURROUNDING_MARK), followed by an element END_MARK_TAG.
     * The value is balanced if that element ends up directly in the innermost surrounding element,
     * or only inside elements of the value whose end tag may be omitted (OPTIONAL_END_TAGS).
     * Otherwise:
     * - an element of the value encloses it: `unclosed tag <x>` (the element extends over the
     *   markup following the value in a page);
     * - a surrounding element no longer encloses it: `unbalanced markup closes a surrounding <x>`
     *   (an end tag of the value, also a misnested one like `<li><div></li></div>`, closes that
     *   element of the page);
     * - it is missing (e.g. inside an unclosed `<textarea>` or `<template>`): `unbalanced markup`.
     *
     * @param array<string, true> $violations
     */
    private function collectUnbalancedMarkup(string $value, array &$violations): void
    {
        $open = '';
        foreach (self::SURROUNDING_ELEMENTS as $index => $tag) {
            $open .= sprintf('<%s %s="%d">', $tag, self::SURROUNDING_MARK, $index);
        }
        // Deliberately without the end tags of the surrounding elements: an end tag after an element
        // the value leaves open (e.g. a `<p>`) moves that element (adoption agency algorithm) and
        // with it the end mark
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head></head><body>' . $open . $value . '<' . self::END_MARK_TAG . '>',
            LIBXML_NOERROR,
            'UTF-8'
        );
        $end_marks = $document->getElementsByTagName(self::END_MARK_TAG);
        $end_mark = $end_marks->item($end_marks->length - 1);
        if ($end_mark === null) {
            $violations['unbalanced markup'] = true;
            return;
        }

        // The ancestors of the end mark, innermost first: those of the value it is in, then the
        // surrounding elements (their index; a clone the parser made of one when reopening it
        // carries the index of the original, too)
        $surrounding = [];
        $unclosed = [];
        for ($ancestor = $end_mark->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if ($ancestor->hasAttribute(self::SURROUNDING_MARK)) {
                $surrounding[] = (int) $ancestor->getAttribute(self::SURROUNDING_MARK);
            } elseif ($surrounding === [] && !in_array($this->tagOf($ancestor), self::OPTIONAL_END_TAGS, true)) {
                $unclosed[$this->tagOf($ancestor)] = true;
            }
        }
        $surrounding = array_reverse($surrounding);
        // Still enclosed by every surrounding element, in its order, without one closed and reopened
        $kept = 0;
        while ($kept < count($surrounding) && $surrounding[$kept] === $kept) {
            $kept++;
        }
        if ($kept < count(self::SURROUNDING_ELEMENTS) || count($surrounding) !== $kept) {
            $violations[sprintf(
                'unbalanced markup closes a surrounding <%s>',
                self::SURROUNDING_ELEMENTS[min($kept, count(self::SURROUNDING_ELEMENTS) - 1)]
            )] = true;
            return;
        }
        foreach (array_reverse(array_keys($unclosed)) as $tag) {
            $violations[sprintf('unclosed tag <%s>', $tag)] = true;
        }
    }

    /**
     * Every end tag in $value whose tag is not allowed (see ALLOWED_ATTRIBUTES), e.g. `</form>`: a
     * parser drops it if no such element is open - in the value alone always, so neither the other
     * parses nor collectUnbalancedMarkup() (only SURROUNDING_ELEMENTS) see it - but in a page it closes
     * that element around the value (`Speichern</form>` closes the page's form). An end tag starts
     * with "</" and an ASCII letter; its name ends at white space, "/" or ">" (as for an HTML5
     * tokenizer). "</" followed by anything else (`a </ b`) is no end tag. A match inside an
     * attribute value, comment or raw text is no end tag to a browser, but each of these is a
     * violation anyway ("<" in an attribute value, comment, disallowed tag).
     *
     * @param array<string, true> $violations
     */
    private function collectEndTagsOfDisallowedTags(string $value, array &$violations): void
    {
        $position = 0;
        while (($position = strpos($value, '</', $position)) !== false) {
            $position += 2;
            if (!ctype_alpha($value[$position] ?? '')) {
                continue;
            }
            $tag = strtolower(substr($value, $position, strcspn($value, self::CSS_WHITESPACE . '/>', $position)));
            if (!array_key_exists($tag, self::ALLOWED_ATTRIBUTES)) {
                $violations[sprintf('end tag </%s> of a tag that is not allowed', mb_strimwidth($tag, 0, 40, '...', 'UTF-8'))] = true;
            }
        }
    }

    /**
     * The sentinels in the text of $parent (textContent: the Text nodes only, no comments)
     */
    private function countSentinelsInText(Node $parent): int
    {
        return substr_count($parent->textContent ?? '', self::SENTINEL);
    }

    /**
     * @param array<string, true> $violations
     */
    private function collectFromTableParse(string $value, array &$violations): void
    {
        $this->collectFromChildren($this->parseInto('table', $value), $violations);
    }

    /**
     * @param array<string, true> $violations
     */
    private function collectStructuralAttributes(Element $element, array &$violations): void
    {
        foreach ($element->attributes as $attribute) {
            $violations[sprintf('attribute "%s" of <%s>', strtolower($attribute->name), $element->localName)] = true;
        }
    }

    /**
     * @param array<string, true> $violations
     */
    private function collectFromChildren(Node $parent, array &$violations): void
    {
        $this->collectFromNodes(iterator_to_array($parent->childNodes, false), $violations);
    }

    /**
     * @param array<string, true> $violations
     */
    private function collectFromNode(Node $node, array &$violations): void
    {
        $this->collectFromNodes([$node], $violations);
    }

    /**
     * The violations in $nodes and their descendants, in document order - iteratively, see the class
     * comment.
     *
     * @param list<Node> $nodes
     * @param array<string, true> $violations
     */
    private function collectFromNodes(array $nodes, array &$violations): void
    {
        $pending = array_reverse($nodes);
        while (($node = array_pop($pending)) !== null) {
            if ($node instanceof Comment) {
                // Also what a browser makes of broken markup such as "</ i>" or "<?...>"
                $violations[sprintf('comment "%s"', mb_strimwidth($node->data, 0, 40, '...', 'UTF-8'))] = true;
                continue;
            }
            if (!$node instanceof Element) {
                continue;
            }

            $tag = $this->tagOf($node);
            if (!$this->isAllowedTag($node)) {
                $violations[sprintf('tag <%s>', $tag)] = true;
            } else {
                foreach ($node->attributes as $attribute) {
                    $violation = $this->attributeViolation($tag, strtolower($attribute->name), $attribute->value);
                    if ($violation !== null) {
                        $violations[$violation] = true;
                    }
                }
            }
            array_push($pending, ...array_reverse(iterator_to_array($node->childNodes, false)));
        }
    }

    private function tagOf(Element $element): string
    {
        return strtolower($element->localName);
    }

    private function isAllowedTag(Element $element): bool
    {
        return $element->namespaceURI === self::HTML_NAMESPACE
            && array_key_exists($this->tagOf($element), self::ALLOWED_ATTRIBUTES);
    }

    /**
     * `null` if $attribute (lowercase) with $value is allowed on the allowed $tag.
     */
    private function attributeViolation(string $tag, string $attribute, string $value): ?string
    {
        if (!in_array($attribute, self::ALLOWED_ATTRIBUTES[$tag], true)) {
            return sprintf('attribute "%s" of <%s>', $attribute, $tag);
        }
        if (isset(self::URL_SCHEMES[$attribute]) && !$this->isAllowedUrl($value, self::URL_SCHEMES[$attribute])) {
            return sprintf('URL "%s" in "%s" of <%s>', mb_strimwidth($value, 0, 60, '...', 'UTF-8'), $attribute, $tag);
        }
        if ($attribute === 'style' && !$this->isAllowedStyle($value)) {
            return sprintf('style "%s" of <%s>', mb_strimwidth($value, 0, 60, '...', 'UTF-8'), $tag);
        }
        // Harmless in the attribute itself, but a value is also inserted where no attribute exists
        // for the parser: in raw text (`<title>`, `<textarea>`: `class="</title><img ...>"`) or a
        // script (`class="<!--<script>"`) the "<" would start markup
        if (str_contains($value, '<')) {
            return sprintf('"<" in attribute "%s" of <%s>', $attribute, $tag);
        }

        return null;
    }

    /**
     * $url is the attribute value as the parser decoded it (character references resolved). Like a
     * browser's URL parser, leading and trailing C0 control characters and spaces are ignored, and
     * tabs and line breaks anywhere, before the scheme is determined.
     *
     * @param list<string> $schemes
     */
    private function isAllowedUrl(string $url, array $schemes): bool
    {
        $url = str_replace(["\t", "\n", "\r"], '', trim($url, "\x00..\x20"));
        // The scheme syntax of RFC 3986 / the WHATWG URL standard; anything else is a relative URL
        // (a path, "?query", "#fragment" or a placeholder like "%1$s")
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $matches) !== 1) {
            return true;
        }

        return in_array(strtolower($matches[1]), $schemes, true);
    }

    /**
     * Whether every declaration of the `style` attribute value $style (as the parser decoded it) sets
     * one of STYLE_PROPERTIES to a value of keywords, numbers, units, "#" colours, "!important" and
     * the functions of STYLE_FUNCTIONS only. A backslash (CSS escape, e.g. `\75rl(`) or a "/" (CSS
     * comment) is never allowed - either could hide a property or function name from this check.
     */
    private function isAllowedStyle(string $style): bool
    {
        if (strpbrk($style, '\\/') !== false) {
            return false;
        }
        foreach (explode(';', $style) as $declaration) {
            if (trim($declaration, self::CSS_WHITESPACE) === '') {
                continue;
            }
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                return false;
            }
            $property = strtolower(trim($parts[0], self::CSS_WHITESPACE));
            $property_value = strtolower(trim($parts[1], self::CSS_WHITESPACE));
            if (
                !in_array($property, self::STYLE_PROPERTIES, true)
                || preg_match('/^[a-z0-9#%.,()!\s-]*$/', $property_value) !== 1
            ) {
                return false;
            }
            preg_match_all('/([a-z-]*)\(/', $property_value, $functions);
            if (array_diff($functions[1], self::STYLE_FUNCTIONS) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * One round of sanitize(): parsed as the content of a <div> (a start tag an HTML5 parser ignores
     * there, e.g. a stray <td>, is dropped by that alone), cleaned, serialised.
     */
    private function rebuild(string $value): string
    {
        $container = $this->parseIntoDiv($value);
        $this->cleanChildren($container);

        return $container->innerHTML;
    }

    private function parseIntoDiv(string $value): Element
    {
        return $this->parseInto('div', $value);
    }

    /**
     * $value parsed as the content of an element $tag. The element is the root of its document:
     * PHP frees a detached element recursively, which crashes for deeply nested content (see the
     * class comment) - a document is freed without recursion.
     */
    private function parseInto(string $tag, string $value): Element
    {
        $document = HTMLDocument::createEmpty();
        $container = $document->createElement($tag);
        $document->appendChild($container);
        $container->innerHTML = $value;

        return $container;
    }

    /**
     * Removes from the descendants of $parent everything that is not allowed (see sanitize()) -
     * iteratively, see the class comment.
     */
    private function cleanChildren(Node $parent): void
    {
        // Snapshots: children are removed or replaced while iterating
        $pending = array_reverse(iterator_to_array($parent->childNodes, false));
        while (($node = array_pop($pending)) !== null) {
            if ($node instanceof Comment) {
                $node->remove();
                continue;
            }
            if (!$node instanceof Element) {
                continue;
            }

            $children = iterator_to_array($node->childNodes, false);
            if (!$this->isAllowedTag($node)) {
                if ($this->tagOf($node) === 'template') {
                    $node->remove();
                    continue;
                }
                $node->replaceWith(...$children);
            } else {
                $tag = $this->tagOf($node);
                foreach (iterator_to_array($node->attributes, false) as $attribute) {
                    if ($this->attributeViolation($tag, strtolower($attribute->name), $attribute->value) !== null) {
                        $node->removeAttributeNode($attribute);
                    }
                }
            }
            array_push($pending, ...array_reverse($children));
        }
    }
}
