<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FULLTEXT indexes for global search (Phase 7.7). MySQL only — the
     * SQLite test database falls back to LIKE in SearchService.
     */
    private const INDEXES = [
        'controls' => ['control_ref', 'title', 'description'],
        'risks' => ['code', 'title', 'description'],
        'control_exceptions' => ['reference', 'title', 'description'],
        'test_instances' => ['reference', 'period_label'],
        'spot_checks' => ['reference', 'title', 'scope_description'],
        'findings' => ['observation', 'recommendation'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function ($blueprint) use ($table, $columns) {
                $blueprint->fullText($columns, "ft_{$table}_search");
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $table) {
            Schema::table($table, function ($blueprint) use ($table) {
                $blueprint->dropFullText("ft_{$table}_search");
            });
        }
    }
};
