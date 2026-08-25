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
     * Order matters. The old unique index is dropped BEFORE due_date is
     * made nullable, because SQLite implements a column change as a table
     * rebuild and an index dropped afterwards may no longer be there to
     * drop.
     */
    public function up(): void
    {
        Schema::table('test_instances', function (Blueprint $table) {
            $table->dropUnique(['control_id', 'period_label']);
        });

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

        Schema::table('test_instances', function (Blueprint $table) {
            $table->unique(['control_id', 'scope_key', 'period_label', 'frequency_id'], 'test_instances_scope_period_unique');
            $table->index(['tenant_id', 'control_entity_id', 'status']);
            $table->index(['tenant_id', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::table('test_instances', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'period_year']);
            $table->dropIndex(['tenant_id', 'control_entity_id', 'status']);
            $table->dropUnique('test_instances_scope_period_unique');
            $table->dropColumn(['trigger_context', 'trigger_event', 'period_year', 'scope_key']);
            $table->dropConstrainedForeignId('frequency_id');
            $table->dropConstrainedForeignId('control_entity_id');
        });

        Schema::table('test_instances', function (Blueprint $table) {
            $table->unique(['control_id', 'period_label']);
        });
    }
};
