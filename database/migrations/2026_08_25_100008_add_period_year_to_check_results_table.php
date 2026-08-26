<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §C.7: the partition and retention key on the largest table
     * in the platform. At the client's branch count the departmental
     * checklist writes roughly 28 million check_results a year, and both
     * the retention sweep and any MySQL RANGE partitioning need a cheap
     * way to say "the current year" without joining to test_instances.
     *
     * Denormalised on purpose, and written by the model's saving hook
     * from the instance's period — a check result cannot outlive or
     * predate the occurrence it belongs to.
     *
     * Partitioning itself is an operational step, not a migration:
     * see docs/runbooks/control-task-partitioning.md.
     */
    public function up(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('period_year')->nullable()->after('check_item_id');
            $table->index(['period_year', 'test_instance_id']);
        });

        DB::table('check_results')->update([
            'period_year' => DB::raw('(select ti.period_year from test_instances ti where ti.id = check_results.test_instance_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->dropIndex(['period_year', 'test_instance_id']);
            $table->dropColumn('period_year');
        });
    }
};
