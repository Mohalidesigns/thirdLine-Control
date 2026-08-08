<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A deliberately tiny arithmetic evaluator for calculated metrics (10.5).
 *
 * eval() is never used and never will be: the input is tokenised, every
 * token must match a whitelisted pattern, and the parse is a hand-written
 * recursive descent over + - * / % ^, parentheses, numeric literals,
 * [metric.CODE] references and a fixed function list. Anything else — a
 * PHP function name, a variable, a backtick, a semicolon — fails to
 * tokenise and throws before evaluation begins.
 */
final class ExpressionEvaluator
{
    /** Whitelisted functions: name => [arity min, arity max]. */
    public const FUNCTIONS = [
        'min' => [1, 99],
        'max' => [1, 99],
        'abs' => [1, 1],
        'round' => [1, 2],
        'floor' => [1, 1],
        'ceil' => [1, 1],
        'sqrt' => [1, 1],
        'avg' => [1, 99],
        'sum' => [1, 99],
        'if' => [3, 3],
    ];

    /** @var array<int, array{type: string, value: string}> */
    private array $tokens = [];

    private int $position = 0;

    /**
     * @param  array<string, float>  $references  metric code => value
     */
    public function __construct(private array $references = []) {}

    /**
     * Every [metric.CODE] reference in an expression, without evaluating it.
     *
     * @return array<int, string>
     */
    public static function referencedCodes(string $expression): array
    {
        preg_match_all('/\[metric\.([A-Za-z0-9_\-]+)\]/', $expression, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** Tokenise-only pass: proves an expression is well-formed and safe. */
    public static function assertSafe(string $expression): void
    {
        (new self)->tokenise($expression);
    }

    public function evaluate(string $expression): float
    {
        $this->tokens = $this->tokenise($expression);
        $this->position = 0;

        $value = $this->parseExpression();

        if ($this->position < count($this->tokens)) {
            throw new InvalidArgumentException('Unexpected trailing input in expression.');
        }

        if (! is_finite($value)) {
            throw new InvalidArgumentException('Expression produced a non-finite result.');
        }

        return $value;
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    private function tokenise(string $expression): array
    {
        if (strlen($expression) > 1000) {
            throw new InvalidArgumentException('Expression is too long.');
        }

        $tokens = [];
        $offset = 0;
        $length = strlen($expression);

        while ($offset < $length) {
            $rest = substr($expression, $offset);

            if (preg_match('/^\s+/', $rest, $m)) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/^\[metric\.([A-Za-z0-9_\-]+)\]/', $rest, $m)) {
                $tokens[] = ['type' => 'reference', 'value' => $m[1]];
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/^\d+(\.\d+)?/', $rest, $m)) {
                $tokens[] = ['type' => 'number', 'value' => $m[0]];
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $rest, $m)) {
                $name = strtolower($m[0]);

                if (! array_key_exists($name, self::FUNCTIONS)) {
                    throw new InvalidArgumentException("'{$m[0]}' is not an allowed function.");
                }

                $tokens[] = ['type' => 'function', 'value' => $name];
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/^(<=|>=|==|!=|<|>|[+\-*\/%^(),])/', $rest, $m)) {
                $tokens[] = ['type' => 'operator', 'value' => $m[0]];
                $offset += strlen($m[0]);

                continue;
            }

            throw new InvalidArgumentException(
                'Unsupported character in expression: '.substr($rest, 0, 1)
            );
        }

        if ($tokens === []) {
            throw new InvalidArgumentException('Expression is empty.');
        }

        return $tokens;
    }

    private function parseExpression(): float
    {
        $left = $this->parseAdditive();

        while ($this->isOperator(['<', '>', '<=', '>=', '==', '!='])) {
            $operator = $this->consume()['value'];
            $right = $this->parseAdditive();

            $left = match ($operator) {
                '<' => $left < $right ? 1.0 : 0.0,
                '>' => $left > $right ? 1.0 : 0.0,
                '<=' => $left <= $right ? 1.0 : 0.0,
                '>=' => $left >= $right ? 1.0 : 0.0,
                '==' => abs($left - $right) < PHP_FLOAT_EPSILON ? 1.0 : 0.0,
                default => abs($left - $right) >= PHP_FLOAT_EPSILON ? 1.0 : 0.0,
            };
        }

        return $left;
    }

    private function parseAdditive(): float
    {
        $value = $this->parseMultiplicative();

        while ($this->isOperator(['+', '-'])) {
            $operator = $this->consume()['value'];
            $right = $this->parseMultiplicative();
            $value = $operator === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function parseMultiplicative(): float
    {
        $value = $this->parsePower();

        while ($this->isOperator(['*', '/', '%'])) {
            $operator = $this->consume()['value'];
            $right = $this->parsePower();

            if (in_array($operator, ['/', '%'], true) && abs($right) < PHP_FLOAT_EPSILON) {
                throw new InvalidArgumentException('Division by zero in expression.');
            }

            $value = match ($operator) {
                '*' => $value * $right,
                '/' => $value / $right,
                default => fmod($value, $right),
            };
        }

        return $value;
    }

    private function parsePower(): float
    {
        $base = $this->parseUnary();

        if ($this->isOperator(['^'])) {
            $this->consume();
            $exponent = $this->parsePower();

            if (abs($exponent) > 32) {
                throw new InvalidArgumentException('Exponent out of range.');
            }

            return $base ** $exponent;
        }

        return $base;
    }

    private function parseUnary(): float
    {
        if ($this->isOperator(['-'])) {
            $this->consume();

            return -$this->parseUnary();
        }

        if ($this->isOperator(['+'])) {
            $this->consume();

            return $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): float
    {
        $token = $this->peek();

        if ($token === null) {
            throw new InvalidArgumentException('Unexpected end of expression.');
        }

        if ($token['type'] === 'number') {
            $this->consume();

            return (float) $token['value'];
        }

        if ($token['type'] === 'reference') {
            $this->consume();

            if (! array_key_exists($token['value'], $this->references)) {
                throw new InvalidArgumentException("No value available for metric [{$token['value']}].");
            }

            return (float) $this->references[$token['value']];
        }

        if ($token['type'] === 'function') {
            return $this->parseFunction();
        }

        if ($token['value'] === '(') {
            $this->consume();
            $value = $this->parseExpression();
            $this->expect(')');

            return $value;
        }

        throw new InvalidArgumentException("Unexpected token '{$token['value']}' in expression.");
    }

    private function parseFunction(): float
    {
        $name = $this->consume()['value'];
        $this->expect('(');

        $arguments = [];

        if (! $this->isOperator([')'])) {
            $arguments[] = $this->parseExpression();

            while ($this->isOperator([','])) {
                $this->consume();
                $arguments[] = $this->parseExpression();
            }
        }

        $this->expect(')');

        [$minArity, $maxArity] = self::FUNCTIONS[$name];

        if (count($arguments) < $minArity || count($arguments) > $maxArity) {
            throw new InvalidArgumentException("{$name}() received the wrong number of arguments.");
        }

        return match ($name) {
            'min' => min($arguments),
            'max' => max($arguments),
            'abs' => abs($arguments[0]),
            'round' => round($arguments[0], (int) ($arguments[1] ?? 0)),
            'floor' => floor($arguments[0]),
            'ceil' => ceil($arguments[0]),
            'sqrt' => $arguments[0] < 0
                ? throw new InvalidArgumentException('sqrt() of a negative number.')
                : sqrt($arguments[0]),
            'avg' => array_sum($arguments) / count($arguments),
            'sum' => array_sum($arguments),
            'if' => abs($arguments[0]) >= PHP_FLOAT_EPSILON ? $arguments[1] : $arguments[2],
        };
    }

    /** @param array<int, string> $values */
    private function isOperator(array $values): bool
    {
        $token = $this->peek();

        return $token !== null && $token['type'] === 'operator' && in_array($token['value'], $values, true);
    }

    private function expect(string $value): void
    {
        if (! $this->isOperator([$value])) {
            throw new InvalidArgumentException("Expected '{$value}' in expression.");
        }

        $this->consume();
    }

    /** @return array{type: string, value: string}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    /** @return array{type: string, value: string} */
    private function consume(): array
    {
        $token = $this->peek();

        if ($token === null) {
            throw new InvalidArgumentException('Unexpected end of expression.');
        }

        $this->position++;

        return $token;
    }
}
