<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §B.1b. App\Models\InvestigationCase became App\Models\SpeakUpCase
 * so the name is free for the casework aggregate on `investigations`.
 *
 * The class name is not only in code: every polymorphic column in the
 * product stores an unaliased FQCN (there is no morph map), so an existing
 * install has audit rows — and possibly AI interaction rows — pointing at
 * a class that no longer exists. Left alone, an audit trail for a case
 * would silently resolve to nothing, which is the one thing an audit trail
 * may never do.
 *
 * Reversible in both directions, because the rename itself is.
 */
return new class extends Migration
{
    private const OLD = 'App\Models\InvestigationCase';

    private const NEW = 'App\Models\SpeakUpCase';

    /** table => morph type columns that may hold the class name. */
    private const COLUMNS = [
        'audit_trails' => ['entity_type'],
        'ai_interactions' => ['subject_type', 'applied_type'],
        'evidence' => ['linked_type'],
        'object_versions' => ['versionable_type'],
        'attestations' => ['attestable_type'],
    ];

    public function up(): void
    {
        $this->rewrite(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rewrite(self::NEW, self::OLD);
    }

    private function rewrite(string $from, string $to): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }
    }
};
