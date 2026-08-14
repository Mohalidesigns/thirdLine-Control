<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The handful of date expressions that differ between MySQL (production)
 * and SQLite (the test suite). Trend widgets group by month or day at the
 * database — pulling a year of rows into PHP to bucket them would breach the
 * performance budget on the very pages that need it most (R6) — so the
 * expression has to be portable rather than avoided.
 */
final class SqlDialect
{
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** 'YYYY-MM' bucket for a date or datetime column. */
    public static function month(string $column): string
    {
        return self::driver() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "date_format({$column}, '%Y-%m')";
    }

    /** 'YYYY-MM-DD' bucket. date() is spelled the same in both engines. */
    public static function day(string $column): string
    {
        return "date({$column})";
    }

    /** Today, for comparisons inside a raw expression. */
    public static function today(): string
    {
        return self::driver() === 'sqlite' ? "date('now')" : 'curdate()';
    }

    /**
     * A date column shifted back by the number of days held in another
     * column — "the day notice had to be given", where the notice period is
     * per-contract data rather than a constant (17.2).
     */
    public static function dateMinusDaysColumn(string $dateColumn, string $daysColumn): string
    {
        return self::driver() === 'sqlite'
            ? "date({$dateColumn}, '-' || {$daysColumn} || ' days')"
            : "date_sub({$dateColumn}, interval {$daysColumn} day)";
    }
}
