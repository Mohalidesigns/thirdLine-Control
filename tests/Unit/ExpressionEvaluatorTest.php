<?php

namespace Tests\Unit;

use App\Support\ExpressionEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExpressionEvaluatorTest extends TestCase
{
    private function evaluate(string $expression, array $references = []): float
    {
        return (new ExpressionEvaluator($references))->evaluate($expression);
    }

    public function test_it_evaluates_arithmetic_with_precedence_and_parentheses(): void
    {
        $this->assertSame(14.0, $this->evaluate('2 + 3 * 4'));
        $this->assertSame(20.0, $this->evaluate('(2 + 3) * 4'));
        $this->assertSame(8.0, $this->evaluate('2 ^ 3'));
        $this->assertSame(-5.0, $this->evaluate('-5'));
        $this->assertSame(1.0, $this->evaluate('7 % 3'));
    }

    public function test_it_resolves_metric_references(): void
    {
        $value = $this->evaluate('[metric.KRI-OPS-LOSS] / [metric.KRI-TXN-VOL] * 10000', [
            'KRI-OPS-LOSS' => 5_000_000,
            'KRI-TXN-VOL' => 500_000_000_000,
        ]);

        $this->assertEqualsWithDelta(0.1, $value, 0.0001);
    }

    public function test_it_supports_only_the_whitelisted_functions(): void
    {
        $this->assertSame(3.0, $this->evaluate('min(3, 9)'));
        $this->assertSame(9.0, $this->evaluate('max(3, 9)'));
        $this->assertSame(6.0, $this->evaluate('avg(4, 8)'));
        $this->assertSame(4.0, $this->evaluate('sqrt(16)'));
        $this->assertSame(4.5, $this->evaluate('round(2.4567, 1) + 2.0'));
        $this->assertSame(1.0, $this->evaluate('if(2 > 1, 1, 0)'));
    }

    /**
     * The rule the phase brief calls out: an expression is parsed, never
     * executed. Every one of these must fail before evaluation begins.
     */
    #[DataProvider('unsafeExpressions')]
    public function test_it_rejects_unsafe_input(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluate($expression, ['A' => 1]);
    }

    public static function unsafeExpressions(): array
    {
        return [
            'php function call' => ['phpinfo()'],
            'shell exec' => ['exec("rm -rf /")'],
            'system call' => ['system("id")'],
            'file read' => ['file_get_contents("/etc/passwd")'],
            'variable interpolation' => ['$user'],
            'statement separator' => ['1; echo 1'],
            'backticks' => ['`id`'],
            'string literal' => ['"select * from users"'],
            'property access' => ['a->b'],
            'static call' => ['App::make()'],
            'nested eval' => ['eval("1+1")'],
            'unknown reference' => ['[metric.NOT-CAPTURED]'],
            'unbalanced parentheses' => ['(1 + 2'],
            'empty expression' => ['   '],
            'trailing operator' => ['1 +'],
            'division by zero' => ['1 / 0'],
            'wrong arity' => ['abs(1, 2)'],
            'negative square root' => ['sqrt(0 - 4)'],
            'runaway exponent' => ['2 ^ 9999'],
        ];
    }

    public function test_assert_safe_accepts_a_valid_expression_without_values(): void
    {
        ExpressionEvaluator::assertSafe('([metric.A] + [metric.B]) / 2');

        $this->assertSame(['A', 'B'], ExpressionEvaluator::referencedCodes('([metric.A] + [metric.B]) / 2'));
    }

    public function test_assert_safe_rejects_an_expression_before_it_is_stored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExpressionEvaluator::assertSafe('shell_exec("uname -a")');
    }

    public function test_a_very_long_expression_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluate(str_repeat('1+', 600).'1');
    }
}
