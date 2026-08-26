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
 * install has rows pointing at a class that no longer exists. Where the
 * stored value is used to LOAD a record — evidence, AI interactions,
 * versions, attestations — it has to be rewritten or the relation resolves
 * to nothing.
 *
 * `audit_trails` is deliberately NOT in that list.
 *
 * The first draft of this migration did rewrite it, and the database
 * refused: audit_trails carries BEFORE UPDATE and BEFORE DELETE triggers
 * that raise SQLSTATE 45000, installed by `atheris:install-audit-triggers`.
 * The trigger was right and the migration was wrong. Rewriting audit
 * history so a class name reads more tidily is exactly the act an immutable
 * audit trail exists to make impossible, and a migration that quietly
 * edited 18 historical rows would have been a worse bug than the one it was
 * fixing.
 *
 * Nothing instantiates a model from audit_trails.entity_type — the log
 * displays class_basename() and filters on exact match — so the cost of
 * leaving history alone is narrow and cosmetic: the Activity Log's
 * entity-type filter would otherwise list the same subject twice, once
 * under each name. That is handled at READ time instead, by
 * AuditTrail::RENAMED_TYPES. History says what it said on the day.
 *
 * Reversible in both directions, because the rename itself is.
 */
return new class extends Migration
{
    private const OLD = 'App\Models\InvestigationCase';

    private const NEW = 'App\Models\SpeakUpCase';

    /**
     * Table => morph type columns that may hold the class name.
     *
     * audit_trails is absent on purpose — see the note above. Every table
     * here is one where the stored FQCN is used to LOAD the record.
     */
    private const COLUMNS = [
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
