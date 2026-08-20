<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 17 adds three more ways an exception can arise, all of them
     * routed through the same ExceptionService as every other source:
     *
     *  - 'Third Party' — a lapsed due-diligence review, or a screening hit
     *    nobody dispositioned (17.2);
     *  - 'Sustainability' — a control over sustainability reporting that
     *    failed, or GHG data that could not be verified (17.3);
     *  - 'Assurance' — a significant risk with no assurance coverage at
     *    all (17.4).
     *
     * Additive only; existing rows, routes and the /api/v1 contract are
     * untouched (R8).
     */
    public function up(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', [
                'Test', 'Spot Check', 'Manual', 'CSA', 'KRI', 'Incident', 'Complaint',
                'Monitoring', 'SoD', 'Third Party', 'Sustainability', 'Assurance', 'Internal Audit',
            ])->default('Manual')->change();

            // The originating system's identifier, for records arriving over
            // the ThirdLine/NexusRisk interlock (17.5). Unique per tenant so
            // an audit system that retries updates its finding rather than
            // duplicating it.
            $table->string('external_ref')->nullable()->after('source_id');
            $table->unique(['tenant_id', 'external_ref'], 'control_exceptions_tenant_external_ref_unique');
        });
    }

    /**
     * Narrowing an enum cannot keep values the narrowed enum has no room
     * for, so any exception raised by a Phase 17 source is first moved to
     * 'Manual' — otherwise the ALTER truncates the column and the rollback
     * dies part-way through. The distinction is genuinely lost on the way
     * down; that is inherent to reversing this migration, and the reference
     * and description on each exception still say where it came from.
     */
    public function down(): void
    {
        DB::table('control_exceptions')
            ->whereIn('source_type', ['Third Party', 'Sustainability', 'Assurance', 'Internal Audit'])
            ->update(['source_type' => 'Manual']);

        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->dropUnique('control_exceptions_tenant_external_ref_unique');
            $table->dropColumn('external_ref');
        });

        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', [
                'Test', 'Spot Check', 'Manual', 'CSA', 'KRI', 'Incident', 'Complaint',
                'Monitoring', 'SoD',
            ])->default('Manual')->change();
        });
    }
};
