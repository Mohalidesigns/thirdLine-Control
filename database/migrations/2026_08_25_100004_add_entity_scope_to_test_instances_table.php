<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §C.3 — the blocking fix. test_instances was unique on
     * (control_id, period_label), so ONE branch-control function shared
     * by a hundred branches could produce exactly one instance per day
     * for the whole network: every branch would fight over the same row.
     *
     * scope_key exists because MySQL does not collide NULLs inside a
     * unique index — a nullable control_entity_id alone would silently
     * permit duplicate global instances and re-break the idempotency the
     * nightly job depends on. It is written by the model's saving hook,
     * never by hand.
     *
     * Additive and backfilled: every existing row becomes scope_key
     * 'global' with a null frequency_id, which is exactly what the old
     * unique index meant. Nothing that is not entity-scoped changes.
     *
     * Order matters, and it is dictated by MySQL: the NEW unique index is
     * created before the old one is dropped. Both lead with control_id,
     * and MySQL refuses to drop an index that is the only one covering a
     * foreign key — dropping first would fail on the real deployment
     * while passing happily on SQLite, which has no such rule.
     */
    public function up(): void
    {
        Schema::table('test_instances', function (Blueprint $table) {
            $table->foreignId('control_entity_id')->nullable()->after('control_id')
                ->constrained('control_entities')->nullOnDelete();
            $table->string('scope_key', 40)->default('global')->after('control_entity_id');
            $table->foreignId('frequency_id')->nullable()->after('period_label')
                ->constrained('control_frequencies')->nullOnDelete();
            // §C.7: the partition key. Written on save; a MySQL deployment
            // ranges on it, everything else just gets a cheap year filter.
            $table->unsignedSmallInteger('period_year')->nullable()->after('period_end');
            // Event triggers record WHAT fired them (§C.5).
            $table->string('trigger_event')->nullable()->after('is_ad_hoc');
            $table->json('trigger_context')->nullable()->after('trigger_event');
        });

        // A continuous observation task has a reporting window but no
        // deadline — it must never appear in the overdue queue (§C.5).
        Schema::table('test_instances', function (Blueprint $table) {
            $table->date('due_date')->nullable()->change();
        });

        DB::table('test_instances')->update(['scope_key' => 'global']);

        // Driver-portable year backfill — the deployment is MySQL, the
        // test suite is SQLite, and neither one's date functions are the
        // other's.
        DB::table('test_instances')->whereNotNull('period_end')->update([
            'period_year' => DB::raw(match (DB::getDriverName()) {
                'sqlite' => "cast(strftime('%Y', period_end) as integer)",
                'pgsql' => 'extract(year from period_end)',
                default => 'year(period_end)',
            }),
        ]);

        // Add the new unique index BEFORE dropping the old one. Both lead
        // with control_id, and MySQL refuses to drop an index that is the
        // only one covering a foreign key — leaving no window where
        // test_instances_control_id_foreign is uncovered is what makes
        // this migration survive on the real deployment.
        Schema::table('test_instances', function (Blueprint $table) {
            $table->unique(['control_id', 'scope_key', 'period_label', 'frequency_id'], 'test_instances_scope_period_unique');
        });

        Schema::table('test_instances', function (Blueprint $table) {
            $table->dropUnique(['control_id', 'period_label']);
            $table->index(['tenant_id', 'control_entity_id', 'status']);
            $table->index(['tenant_id', 'period_year']);
        });
    }

    /**
     * The down migration is exercised, but it is not unconditional: once
     * entity-scoped tasks exist, restoring UNIQUE(control_id,
     * period_label) is IMPOSSIBLE by definition — two branches holding
     * the same daily function on the same day is precisely what this
     * migration made legal.
     *
     * So refuse, with the count and the query to see them, rather than
     * failing on a raw duplicate-key error or — far worse — silently
     * deleting task records to make the index fit.
     */
    public function down(): void
    {
        $scoped = DB::table('test_instances')
            ->where(fn ($q) => $q->where('scope_key', '!=', 'global')->orWhereNotNull('frequency_id'))
            ->count();

        if ($scoped > 0) {
            throw new RuntimeException(sprintf(
                'Cannot roll back: %d entity-scoped or rhythm-scoped control task(s) exist, and the old '
                ."UNIQUE(control_id, period_label) index cannot hold them.\n"
                ."Inspect them with:\n"
                .'  select id, reference, control_id, scope_key, period_label from test_instances '
                ."where scope_key <> 'global' or frequency_id is not null;\n"
                .'Archive or delete those rows deliberately before rolling this migration back.',
                $scoped,
            ));
        }

        // Mirror of up(): put the old index back before removing the new
        // one, so the control_id foreign key is never left uncovered.
        Schema::table('test_instances', function (Blueprint $table) {
            $table->unique(['control_id', 'period_label']);
        });

        Schema::table('test_instances', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'period_year']);
            $table->dropIndex(['tenant_id', 'control_entity_id', 'status']);
            $table->dropUnique('test_instances_scope_period_unique');
            $table->dropColumn(['trigger_context', 'trigger_event', 'period_year', 'scope_key']);
            $table->dropConstrainedForeignId('frequency_id');
            $table->dropConstrainedForeignId('control_entity_id');
        });
    }
};
