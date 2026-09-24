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
use Dom\Text;

/**
 * The HTML a translation value may contain - the one place that decides it, for the shipped files
 * (compiled by Setup's build, see ShippedTranslations: cleaned there, with a warning) and for every
 * local write path (rejected there, see findInvalidValues()).
 *
 * Allowed are the tags listed in ALLOWED_ATTRIBUTES, each only with the attributes listed there
 * (attribute names case-insensitive), and `href`/`src` only with a URL that is relative (including
 * `#...`) or uses one of the schemes listed in URL_SCHEMES. An attribute value must not contain
 * "<". Comments, and a value ending inside an unfinished tag, count as a violation, too.
 * Text that is no tag to an HTML parser - `a <= b`, `<<` - stays allowed.
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
 * well and stay in an allowed value. Content inside
 * raw-text contexts (`<textarea>`, `<script>`, ...) is text to a browser and therefore to this
 * check, too - the enclosing tag itself is a violation anyway.
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

    private const string HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /**
     * How often sanitize() rebuilds a value until the result is allowed - re-parsing serialised
     * markup can in theory produce new markup ("mutation"); if it still does after these rounds, only
     * the text is kept.
     */
    private const int MAX_SANITIZE_ROUNDS = 3;

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

        $violations = [];
        $this->collectFromBodyParse($value, $violations);
        $this->collectFromTableParse($value, $violations);
        if ($this->hasIncompleteTagAtEnd($value)) {
            $violations['incomplete tag at the end'] = true;
        }

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
     * (e.g. `<br/>` becomes `<br>`, entities become characters), never in meaning.
     */
    public function sanitize(string $value): string
    {
        if ($this->isAllowed($value)) {
            return $value;
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

        $table = HTMLDocument::createEmpty()->createElement('table');
        $table->innerHTML = $value . self::SENTINEL;

        return $this->countSentinelsInText($table) < $expected;
    }

    private function countSentinelsInText(Node $parent): int
    {
        $count = 0;
        foreach ($parent->childNodes as $child) {
            $count += $child instanceof Text
                ? substr_count($child->data, self::SENTINEL)
                : $this->countSentinelsInText($child);
        }

        return $count;
    }

    /**
     * @param array<string, true> $violations
     */
    private function collectFromTableParse(string $value, array &$violations): void
    {
        $document = HTMLDocument::createEmpty();
        $table = $document->createElement('table');
        $table->innerHTML = $value;
        $this->collectFromChildren($table, $violations);
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
        foreach ($parent->childNodes as $child) {
            $this->collectFromNode($child, $violations);
        }
    }

    /**
     * @param array<string, true> $violations
     */
    private function collectFromNode(Node $node, array &$violations): void
    {
        if ($node instanceof Comment) {
            // Also what a browser makes of broken markup such as "</ i>" or "<?...>"
            $violations[sprintf('comment "%s"', mb_strimwidth($node->data, 0, 40, '...', 'UTF-8'))] = true;
            return;
        }
        if (!$node instanceof Element) {
            return;
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
        $this->collectFromChildren($node, $violations);
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
            return sprintf('URL "%s" in "%s" of <%s>', $value, $attribute, $tag);
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
        $document = HTMLDocument::createEmpty();
        $container = $document->createElement('div');
        $container->innerHTML = $value;

        return $container;
    }

    private function cleanChildren(Node $parent): void
    {
        // A snapshot: children are removed or replaced while iterating
        foreach (iterator_to_array($parent->childNodes, false) as $child) {
            $this->cleanNode($child);
        }
    }

    private function cleanNode(Node $node): void
    {
        if ($node instanceof Comment) {
            $node->remove();
            return;
        }
        if (!$node instanceof Element) {
            return;
        }

        if (!$this->isAllowedTag($node)) {
            if ($this->tagOf($node) === 'template') {
                $node->remove();
                return;
            }
            $children = iterator_to_array($node->childNodes, false);
            $node->replaceWith(...$children);
            foreach ($children as $child) {
                $this->cleanNode($child);
            }
            return;
        }

        $tag = $this->tagOf($node);
        foreach (iterator_to_array($node->attributes, false) as $attribute) {
            if ($this->attributeViolation($tag, strtolower($attribute->name), $attribute->value) !== null) {
                $node->removeAttributeNode($attribute);
            }
        }
        $this->cleanChildren($node);
    }
}
