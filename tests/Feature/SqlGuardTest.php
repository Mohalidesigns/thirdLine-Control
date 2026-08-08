<?php

namespace Tests\Feature;

use App\Support\SqlGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The custom_sql gate (12.3 business rule): read-only, parameterised,
 * whitelisted, blocked from system tables and from any table the rule
 * did not declare.
 *
 * The rejection cases below are deliberately the ones a denylist misses:
 * mixed case, keyword-adjacent identifiers, stacked statements, comment
 * obfuscation and function-based file access.
 */
class SqlGuardTest extends TestCase
{
    private const TABLES = ['gl_postings', 'entitlements'];

    /** @return array<string, array{0: string}> */
    public static function acceptedStatements(): array
    {
        return [
            'plain select' => ['select transaction_id, amount from gl_postings'],
            'aliased join' => ['select p.transaction_id from gl_postings p join entitlements e on p.maker = e.subject'],
            'aggregate with having' => ['select branch_code, sum(amount) as total from gl_postings group by branch_code having sum(amount) > 100'],
            'cte' => ['with big as (select transaction_id from gl_postings where amount > 100) select count(*) from big'],
            'window function' => ['select row_number() over (partition by maker order by amount desc) as rn from gl_postings'],
            'in list' => ["select transaction_id from gl_postings where channel in ('BRANCH', 'DIGITAL')"],
            'union of own tables' => ['select maker from gl_postings union select subject from entitlements'],
            'case expression' => ['select case when amount > 10 then 1 else 0 end as flag from gl_postings'],
        ];
    }

    #[DataProvider('acceptedStatements')]
    public function test_it_accepts_a_read_only_statement_over_declared_tables(string $sql): void
    {
        $this->assertSame([], SqlGuard::assertReadOnly($sql, self::TABLES));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedStatements(): array
    {
        return [
            'insert' => ['insert into gl_postings values (1)', 'must begin with SELECT'],
            'update' => ['update gl_postings set amount = 0', 'must begin with SELECT'],
            'delete' => ['DELETE FROM gl_postings', 'must begin with SELECT'],
            'mixed-case drop' => ['DrOp TaBlE gl_postings', 'must begin with SELECT'],
            'stacked statement' => ['select 1 from gl_postings; drop table gl_postings', 'single statement'],
            'trailing ddl after union' => ['select 1 from gl_postings union all select 1 from gl_postings; delete from gl_postings', 'single statement'],
            'line comment' => ['select * from gl_postings -- and then', 'Comments are not permitted'],
            'block comment' => ['select /* sneaky */ * from gl_postings', 'Comments are not permitted'],
            'hash comment' => ['select * from gl_postings # nope', 'Comments are not permitted'],
            'select into' => ['select * into other from gl_postings', 'INTO is not permitted'],
            'outfile' => ["select * from gl_postings into outfile '/tmp/x'", 'INTO is not permitted'],
            'system table' => ['select * from sqlite_master', 'system object'],
            'information schema' => ['select * from information_schema', 'system object'],
            'pg catalogue function' => ['select pg_read_file(1) from gl_postings', 'not on the permitted list'],
            'load_file' => ['select load_file(1) from gl_postings', 'not on the permitted list'],
            'undeclared table' => ['select * from payroll', 'not one of'],
            'undeclared table in subquery' => ['select * from gl_postings where maker in (select id from payroll)', 'not one of'],
            'cross database' => ['select * from otherdb.public.gl_postings', 'qualified table reference'],
            'positional placeholder' => ['select * from gl_postings where id = ?', 'named parameters'],
            'parameter on a rule that declares none' => ['select * from gl_postings where id = :ghost', 'declares none'],
            'backslash escape' => ["select * from gl_postings where maker = 'a\\\\'", 'Backslash escapes'],
            'bitwise operator' => ['select amount & 1 from gl_postings', 'is not permitted'],
            'pragma' => ['pragma table_info(gl_postings)', 'must begin with SELECT'],
            'attach' => ["attach database '/tmp/x' as x", 'must begin with SELECT'],
        ];
    }

    #[DataProvider('rejectedStatements')]
    public function test_it_rejects_anything_that_is_not_a_read_only_select(string $sql, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($expected, '/').'/i');

        SqlGuard::assertReadOnly($sql, self::TABLES);
    }

    public function test_a_declared_parameter_is_returned_and_an_undeclared_one_is_refused(): void
    {
        $used = SqlGuard::assertReadOnly(
            'select transaction_id from gl_postings where amount > :limit and branch_code = :branch',
            self::TABLES,
            ['limit', 'branch'],
        );

        $this->assertEqualsCanonicalizing(['limit', 'branch'], $used);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Undeclared parameter/');

        SqlGuard::assertReadOnly(
            'select transaction_id from gl_postings where amount > :limit and maker = :ghost',
            self::TABLES,
            ['limit'],
        );
    }

    public function test_a_rule_with_no_datasets_can_reference_no_tables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares no datasets/');

        SqlGuard::assertReadOnly('select * from gl_postings', []);
    }

    public function test_an_over_long_statement_is_refused_before_parsing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/character limit/');

        SqlGuard::assertReadOnly('select '.str_repeat('a, ', 4000).'b from gl_postings', self::TABLES);
    }
}
