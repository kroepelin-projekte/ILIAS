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

use InvalidArgumentException;

/**
 * The plural rule of a language, as declared by the "Plural-Forms" header of a gettext catalog
 * (`nplurals=<count>; plural=<C expression of n>;`), and the one place that decides which plural
 * form a message serves.
 *
 * The expression is parsed by a small recursive descent parser for exactly the subset the plural
 * formulas of gettext/CLDR use - `n`, non-negative integers, `?:`, `||`, `&&`, `!`, `==`, `!=`, `<`,
 * `<=`, `>`, `>=`, `%` and parentheses - into a tree that is evaluated without eval() or any
 * library: a header comes from a file (a shipped `.po`, an overlay, a plugin), so it is treated as
 * untrusted input. Everything else (other operators, identifiers, very long or deeply nested
 * formulas, an index outside of 0..count-1 for any sampled n) makes the header invalid.
 *
 * Rule for callers that need one value of a plural message (txt(), the database fallback, a
 * plugin's database write): the default form is msgstr[1] if the language has at least two forms,
 * msgstr[0] otherwise - see defaultFormIndex() and defaultValueOf().
 */
final class PluralForms
{
    /**
     * The fallback for a missing or invalid header: the rule of the Germanic languages.
     */
    public const string GERMANIC_HEADER = 'nplurals=2; plural=(n != 1);';

    private const int MAX_COUNT = 10;
    private const int MAX_FORMULA_LENGTH = 512;
    private const int MAX_DEPTH = 64;
    private const int MAX_INTEGER_DIGITS = 9;

    /**
     * The values of n every parsed formula is evaluated for once, to reject a formula that yields an
     * index outside of 0..count-1 (CLDR rules repeat with n % 100 or single out n % 1000000).
     *
     * @var list<int>
     */
    private const array EXTRA_SAMPLES = [1000, 1001, 1011, 100000, 1000000, 1000001, 2000000];
    private const int SAMPLED_UP_TO = 200;

    /**
     * The binary operators by precedence, lowest first (C rules; all left-associative).
     */
    private const array BINARY_LEVELS = [
        ['||'],
        ['&&'],
        ['==', '!='],
        ['<', '<=', '>', '>='],
        ['%'],
    ];

    /**
     * Parsed headers of this request, see fromHeader().
     *
     * @var array<string, self>
     */
    private static array $parsed = [];

    /**
     * @param array<int, mixed> $tree
     */
    private function __construct(
        private readonly int $count,
        private readonly string $formula,
        private readonly array $tree
    ) {
    }

    /**
     * @throws InvalidArgumentException if $header is no valid "Plural-Forms" value, see the class
     *         docblock
     */
    public static function fromHeader(string $header): self
    {
        if (isset(self::$parsed[$header])) {
            return self::$parsed[$header];
        }
        // limited before any regular expression runs on it; possessive quantifiers, no backtracking
        if (strlen($header) > self::MAX_FORMULA_LENGTH + 64) {
            throw new InvalidArgumentException('The Plural-Forms header is too long.');
        }
        if (preg_match('/\A\s*+nplurals\s*+=\s*+(\d{1,2}+)\s*+;\s*+plural\s*+=([^;]++);?\s*+\z/', $header, $matches) !== 1) {
            throw new InvalidArgumentException('The Plural-Forms header does not have the form "nplurals=<count>; plural=<expression>;".');
        }
        $count = (int) $matches[1];
        $formula = trim($matches[2]);
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException(sprintf('nplurals must be between 1 and %d.', self::MAX_COUNT));
        }
        if (strlen($formula) > self::MAX_FORMULA_LENGTH) {
            throw new InvalidArgumentException('The plural expression is too long.');
        }

        $forms = new self($count, $formula, self::parse($formula));
        foreach ([...range(0, self::SAMPLED_UP_TO), ...self::EXTRA_SAMPLES] as $n) {
            $index = $forms->evaluate($n);
            if ($index === null || $index < 0 || $index >= $count) {
                throw new InvalidArgumentException(sprintf(
                    'The plural expression yields no valid form for n = %d (nplurals=%d).',
                    $n,
                    $count
                ));
            }
        }
        if (count(self::$parsed) >= 64) {
            self::$parsed = [];
        }

        return self::$parsed[$header] = $forms;
    }

    /**
     * fromHeader(), or the Germanic rule (GERMANIC_HEADER) if $header is missing or invalid -
     * reported through $on_invalid then.
     *
     * @param (callable(string $message): void)|null $on_invalid
     */
    public static function fromHeaderOrGermanic(?string $header, ?callable $on_invalid = null): self
    {
        if ($header !== null && trim($header) !== '') {
            try {
                return self::fromHeader($header);
            } catch (InvalidArgumentException $e) {
                $message = sprintf('Invalid Plural-Forms header "%s" (%s) - using "%s".', $header, $e->getMessage(), self::GERMANIC_HEADER);
            }
        } else {
            $message = sprintf('Missing Plural-Forms header - using "%s".', self::GERMANIC_HEADER);
        }
        if ($on_invalid !== null) {
            $on_invalid(PlainLogText::of($message));
        }

        return self::germanic();
    }

    public static function germanic(): self
    {
        return self::fromHeader(self::GERMANIC_HEADER);
    }

    /**
     * nplurals: how many forms a plural message of this language has.
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * The rule as a "Plural-Forms" header value.
     */
    public function getHeader(): string
    {
        return sprintf('nplurals=%d; plural=%s;', $this->count, $this->formula);
    }

    /**
     * The index of the form for the quantity $n. A negative $n counts like its absolute value (gettext
     * takes an unsigned number). Should the expression fail for $n (e.g. a modulo by zero only this
     * $n reaches), the default form.
     */
    public function formIndexFor(int $n): int
    {
        $n = $n === PHP_INT_MIN ? PHP_INT_MAX : abs($n);
        $index = $this->evaluate($n);

        return $index !== null && $index >= 0 && $index < $this->count ? $index : $this->defaultFormIndex();
    }

    /**
     * The form serving a plural message where only one value can be used: msgstr[1] if there are at
     * least two forms, msgstr[0] otherwise.
     */
    public function defaultFormIndex(): int
    {
        return self::defaultFormIndexForCount($this->count);
    }

    /**
     * defaultFormIndex() of a language with $count forms.
     */
    public static function defaultFormIndexForCount(int $count): int
    {
        return $count >= 2 ? 1 : 0;
    }

    /**
     * The value of the default form (see defaultFormIndex()) of a message with $forms (msgstr[0],
     * msgstr[1], ...), msgstr[0] if that form is missing or empty.
     *
     * @param array<int, string> $forms
     */
    public function defaultValueOf(array $forms): string
    {
        return self::defaultValueForCount($forms, $this->count);
    }

    /**
     * defaultValueOf() for a language with $count forms.
     *
     * @param array<int, string> $forms
     */
    public static function defaultValueForCount(array $forms, int $count): string
    {
        $value = $forms[self::defaultFormIndexForCount($count)] ?? '';

        return $value !== '' ? $value : ($forms[0] ?? '');
    }

    /**
     * Whether the rule is "n == 1 -> form 0, every other n -> form 1" (e.g. English, German) - for
     * every sampled n.
     */
    public function isOneSingularOtherPlural(): bool
    {
        if ($this->count !== 2) {
            return false;
        }
        foreach ([...range(0, self::SAMPLED_UP_TO), ...self::EXTRA_SAMPLES] as $n) {
            if ($this->formIndexFor($n) !== ($n === 1 ? 0 : 1)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ evaluation

    private function evaluate(int $n): ?int
    {
        try {
            return self::evaluateNode($this->tree, $n);
        } catch (\ArithmeticError) {
            return null;
        }
    }

    /**
     * @param array<int, mixed> $node
     */
    private static function evaluateNode(array $node, int $n): int
    {
        switch ($node[0]) {
            case 'n':
                return $n;
            case 'int':
                return $node[1];
            case '!':
                return self::evaluateNode($node[1], $n) === 0 ? 1 : 0;
            case '?':
                return self::evaluateNode($node[1], $n) !== 0
                    ? self::evaluateNode($node[2], $n)
                    : self::evaluateNode($node[3], $n);
            case '||':
                return self::evaluateNode($node[1], $n) !== 0 || self::evaluateNode($node[2], $n) !== 0 ? 1 : 0;
            case '&&':
                return self::evaluateNode($node[1], $n) !== 0 && self::evaluateNode($node[2], $n) !== 0 ? 1 : 0;
        }

        $left = self::evaluateNode($node[1], $n);
        $right = self::evaluateNode($node[2], $n);

        return match ($node[0]) {
            '==' => (int) ($left === $right),
            '!=' => (int) ($left !== $right),
            '<' => (int) ($left < $right),
            '<=' => (int) ($left <= $right),
            '>' => (int) ($left > $right),
            '>=' => (int) ($left >= $right),
            // intdiv()'s sibling: throws DivisionByZeroError (an ArithmeticError) for 0
            '%' => $left % $right,
            // parse() creates no other node
            default => throw new \LogicException(sprintf('Unknown node "%s" of a plural expression.', (string) $node[0])),
        };
    }

    // ------------------------------------------------------------------ parsing

    /**
     * @return array<int, mixed> the tree of $formula
     * @throws InvalidArgumentException
     */
    private static function parse(string $formula): array
    {
        $tokens = self::tokenize($formula);
        $position = 0;
        $tree = self::parseTernary($tokens, $position, 0);
        if ($position !== count($tokens)) {
            throw new InvalidArgumentException(sprintf('Unexpected "%s" in the plural expression.', $tokens[$position]));
        }

        return $tree;
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException
     */
    private static function tokenize(string $formula): array
    {
        $matched = preg_match_all(
            '/\s*(?:(\d+)|(n)|(\|\||&&|==|!=|<=|>=|[<>!?:%()]))\s*/A',
            $formula,
            $matches,
            PREG_SET_ORDER
        );
        $consumed = 0;
        $tokens = [];
        foreach ($matches ?: [] as $match) {
            $consumed += strlen($match[0]);
            $token = trim($match[0]);
            if (ctype_digit($token) && strlen(ltrim($token, '0')) > self::MAX_INTEGER_DIGITS) {
                throw new InvalidArgumentException('An integer of the plural expression is too large.');
            }
            $tokens[] = $token;
        }
        if ($matched === false || $consumed !== strlen($formula) || $tokens === []) {
            throw new InvalidArgumentException('The plural expression contains something other than n, integers, ?:, ||, &&, !, comparisons, % and parentheses.');
        }

        return $tokens;
    }

    /**
     * ternary := or ( "?" ternary ":" ternary )?
     *
     * @param list<string> $tokens
     * @return array<int, mixed>
     */
    private static function parseTernary(array $tokens, int &$position, int $depth): array
    {
        self::assertDepth($depth);
        $condition = self::parseBinary($tokens, $position, $depth, 0);
        if (($tokens[$position] ?? null) !== '?') {
            return $condition;
        }
        $position++;
        $then = self::parseTernary($tokens, $position, $depth + 1);
        self::expect($tokens, $position, ':');
        $else = self::parseTernary($tokens, $position, $depth + 1);

        return ['?', $condition, $then, $else];
    }

    /**
     * @param list<string> $tokens
     * @return array<int, mixed>
     */
    private static function parseBinary(array $tokens, int &$position, int $depth, int $level): array
    {
        // the levels are a fixed, small number - only parentheses, "!" and "?:" nest (see assertDepth())
        if ($level === count(self::BINARY_LEVELS)) {
            return self::parseUnary($tokens, $position, $depth);
        }
        $left = self::parseBinary($tokens, $position, $depth, $level + 1);
        while (in_array($tokens[$position] ?? null, self::BINARY_LEVELS[$level], true)) {
            $operator = $tokens[$position++];
            $left = [$operator, $left, self::parseBinary($tokens, $position, $depth, $level + 1)];
        }

        return $left;
    }

    /**
     * unary := "!" unary | "n" | integer | "(" ternary ")"
     *
     * @param list<string> $tokens
     * @return array<int, mixed>
     */
    private static function parseUnary(array $tokens, int &$position, int $depth): array
    {
        self::assertDepth($depth);
        $token = $tokens[$position] ?? null;
        if ($token === null) {
            throw new InvalidArgumentException('The plural expression ends unexpectedly.');
        }
        $position++;
        if ($token === '!') {
            return ['!', self::parseUnary($tokens, $position, $depth + 1)];
        }
        if ($token === 'n') {
            return ['n'];
        }
        if (ctype_digit($token)) {
            return ['int', (int) $token];
        }
        if ($token === '(') {
            $inner = self::parseTernary($tokens, $position, $depth + 1);
            self::expect($tokens, $position, ')');
            return $inner;
        }

        throw new InvalidArgumentException(sprintf('Unexpected "%s" in the plural expression.', $token));
    }

    /**
     * @param list<string> $tokens
     */
    private static function expect(array $tokens, int &$position, string $token): void
    {
        if (($tokens[$position] ?? null) !== $token) {
            throw new InvalidArgumentException(sprintf('"%s" expected in the plural expression.', $token));
        }
        $position++;
    }

    private static function assertDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('The plural expression is nested too deeply.');
        }
    }
}
