<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use InvalidArgumentException;

/**
 * PCRE pattern → JS regex literal with the u flag, for the small common subset whose
 * meaning is the same in both engines. Anything else is refused with the reason.
 * JS line terminators are \n, \r, U+2028 and U+2029; PCRE's are \n alone.
 */
final class RegexTranslator
{
    private const string JS_SYNTAX = '^$\\.*+?()[]{}|/';

    private const array CATEGORIES = [
        'L', 'Lu', 'Ll', 'Lt', 'Lm', 'Lo', 'M', 'Mn', 'Mc', 'Me', 'N', 'Nd', 'Nl', 'No',
        'P', 'Pc', 'Pd', 'Ps', 'Pe', 'Pi', 'Pf', 'Po', 'S', 'Sm', 'Sc', 'Sk', 'So',
        'Z', 'Zs', 'Zl', 'Zp', 'C', 'Cc', 'Cf', 'Co', 'Cs', 'Cn',
    ];

    /** @var list<string> */
    private array $chars = [];

    private int $i = 0;

    private bool $unicode = false;

    /**
     * @param  bool  $trimmed  the value never ends in a newline, so PCRE's `$` needs no newline allowance
     */
    public function translate(string $pattern, bool $trimmed): string
    {
        $this->unicode = false;
        $open = $pattern[0] ?? '';

        if ($open === '' || ctype_alnum($open) || ctype_space($open) || $open === '\\') {
            throw new InvalidArgumentException('no pattern delimiter');
        }

        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($pattern, $close);

        if ($end === false || $end === 0) {
            throw new InvalidArgumentException('no closing delimiter');
        }

        $flags = ['i' => false, 's' => false];
        $dollarEndOnly = false;

        foreach (str_split(substr($pattern, $end + 1)) as $modifier) {
            match ($modifier) {
                'i', 's' => $flags[$modifier] = true,
                'm' => throw new InvalidArgumentException('the m modifier: JS also breaks lines at \r, U+2028 and U+2029'),
                'u' => $this->unicode = true,
                'D' => $dollarEndOnly = true,
                default => throw new InvalidArgumentException("the {$modifier} modifier has no JS equivalent"),
            };
        }

        $body = substr($pattern, 1, $end - 1);

        if (str_starts_with($body, '(?i)')) {
            $flags['i'] = true;
            $body = substr($body, 4);
        }

        if ($flags['i'] && ! $this->unicode) {
            throw new InvalidArgumentException('i without the u modifier is ASCII-only in PHP but Unicode case folding in JS; add the u modifier');
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            throw new InvalidArgumentException('the pattern is not UTF-8');
        }

        $this->chars = mb_str_split($body);
        $plainDollar = $dollarEndOnly || $trimmed;
        $out = '';
        $inClass = false;

        for ($this->i = 0; $this->i < count($this->chars); $this->i++) {
            $c = $this->chars[$this->i];

            if (strlen($c) > 1 && ! $this->unicode) {
                throw new InvalidArgumentException('a non-ASCII character without the u modifier; add the u modifier');
            }

            if ($c === '\\') {
                $out .= $this->escape($open, $close, $inClass);

                continue;
            }

            if ($inClass) {
                if ($c === '[' && $this->peek() === ':') {
                    throw new InvalidArgumentException('POSIX classes');
                }

                $inClass = $c !== ']';
                $out .= $c === '/' ? '\\/' : $c;

                continue;
            }

            $out .= match ($c) {
                '[' => $this->openClass($inClass),
                '(' => $this->group(),
                '.' => match (true) {
                    ! $this->unicode => throw new InvalidArgumentException('`.` without the u modifier matches bytes; add the u modifier'),
                    $flags['s'] => '.',
                    default => '[^\\n]',
                },
                '$' => $plainDollar ? '$' : '(?=\\n?$)',
                '/' => '\\/',
                '*', '+', '?' => $this->quantifier($c),
                '{' => $this->quantifier($this->counted()),
                ']', '}' => throw new InvalidArgumentException("an unescaped {$c}"),
                default => $c,
            };
        }

        if ($inClass) {
            throw new InvalidArgumentException('an unterminated character class');
        }

        return '/'.$out.'/'.implode('', array_keys(array_filter($flags))).'u';
    }

    private function peek(int $ahead = 1): string
    {
        return $this->chars[$this->i + $ahead] ?? '';
    }

    private function openClass(bool &$inClass): string
    {
        $negated = $this->peek() === '^';

        if ($negated && ! $this->unicode) {
            throw new InvalidArgumentException('a negated class without the u modifier matches bytes; add the u modifier');
        }

        $this->i += $negated ? 1 : 0;

        if ($this->peek() === ']') {
            throw new InvalidArgumentException('a ] first in a class');
        }

        $inClass = true;

        return $negated ? '[^' : '[';
    }

    private function quantifier(string $quantifier): string
    {
        if ($this->peek() === '+') {
            throw new InvalidArgumentException('possessive quantifiers');
        }

        return $quantifier;
    }

    private function counted(): string
    {
        $rest = implode('', array_slice($this->chars, $this->i, 32));

        if (preg_match('/^\{\d+(,\d*)?\}/', $rest, $m) !== 1) {
            throw new InvalidArgumentException('a { that is not a quantifier');
        }

        $this->i += strlen($m[0]) - 1;

        return $m[0];
    }

    private function group(): string
    {
        if ($this->peek() === '*') {
            throw new InvalidArgumentException('backtracking verbs');
        }

        if ($this->peek() !== '?') {
            return '(';
        }

        $rest = implode('', array_slice($this->chars, $this->i, 40));

        foreach (['(?:', '(?=', '(?!', '(?<=', '(?<!'] as $kind) {
            if (str_starts_with($rest, $kind)) {
                $this->i += strlen($kind) - 1;

                return $kind;
            }
        }

        if (preg_match('/^\(\?P?<([A-Za-z_][A-Za-z0-9_]*)>/', $rest, $m) === 1) {
            $this->i += strlen($m[0]) - 1;

            return "(?<{$m[1]}>";
        }

        throw new InvalidArgumentException('the group '.substr($rest, 0, 4).'… (atomic, conditional, recursive, inline-flag or PCRE-only)');
    }

    private function escape(string $open, string $close, bool $inClass): string
    {
        $c = $this->chars[++$this->i] ?? throw new InvalidArgumentException('a trailing backslash');

        if (($c === $open || $c === $close) && $c !== '/') {
            return str_contains(self::JS_SYNTAX, $c) ? '\\'.$c : $c;
        }

        return match (true) {
            $c === 'A' && ! $inClass => '^',
            $c === 'z' && ! $inClass => '$',
            in_array($c, ['d', 'w', 's', 'b', 'B', 'D', 'W', 'S'], true) => $this->classEscape($c, $inClass),
            in_array($c, ['n', 'r', 't', 'f'], true) => '\\'.$c,
            $c === 'x' => $this->hexEscape(),
            $c === 'p' || $c === 'P' => $this->propertyEscape($c),
            ctype_digit($c) && $c !== '0' && ! $inClass && ! ctype_digit($this->peek()) => '\\'.$c,
            strlen($c) > 1 => $c,
            ctype_alnum($c) => throw new InvalidArgumentException("\\{$c} has no JS equivalent"),
            $c === '-' => $inClass ? '\\-' : '-',
            str_contains(self::JS_SYNTAX, $c) => '\\'.$c,
            default => $c,
        };
    }

    private function classEscape(string $c, bool $inClass): string
    {
        if ($this->unicode) {
            throw new InvalidArgumentException("\\{$c} is Unicode-aware in PHP under u but ASCII in JS; write the class out, e.g. [0-9] or \\p{L}");
        }

        if (in_array($c, ['D', 'W', 'S'], true)) {
            throw new InvalidArgumentException("\\{$c} without the u modifier matches bytes; add the u modifier and write the class out");
        }

        if ($c === 's') {
            // JS \s is Unicode whitespace even without u; PHP's is these six bytes.
            return $inClass ? '\t\n\x0B\f\r ' : '[\t\n\x0B\f\r ]';
        }

        return '\\'.$c;
    }

    private function hexEscape(): string
    {
        $hex = $this->peek().$this->peek(2);

        if (strlen($hex) !== 2 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('\x needs exactly two hex digits');
        }

        if (! $this->unicode && hexdec($hex) > 0x7F) {
            throw new InvalidArgumentException('a non-ASCII \x escape without the u modifier; add the u modifier');
        }

        $this->i += 2;

        return '\\x'.$hex;
    }

    private function propertyEscape(string $c): string
    {
        $rest = implode('', array_slice($this->chars, $this->i + 1, 40));
        $name = preg_match('/^\{\^?([A-Za-z]+)\}|^([A-Za-z])/', $rest, $m) === 1 ? $m[1].($m[2] ?? '') : '';

        if (! $this->unicode || ! in_array($name, self::CATEGORIES, true) || str_starts_with($rest, '{^')) {
            throw new InvalidArgumentException("\\{$c}".substr($rest, 0, 1).'… (only general categories such as \p{L}, with the u modifier)');
        }

        $this->i += strlen($m[0]);

        return '\\'.$c.'{'.$name.'}';
    }
}
