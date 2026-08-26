<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §C.1/§C.6: additive only. controls.frequency (the enum) is
     * KEPT and stays the compatibility surface for every existing reader;
     * frequency_id is the authority when present, and frequency_raw
     * preserves the client's exact wording for the audit trail and for
     * round-tripping the register back to their own workbook.
     */
    public function up(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->foreignId('frequency_id')->nullable()->after('frequency')
                ->constrained('control_frequencies')->nullOnDelete();
            $table->string('frequency_raw')->nullable()->after('frequency_id');
            // The originating workbook cell, e.g. HO!D412.
            $table->string('source_ref', 40)->nullable()->after('external_ref');
            // Marks the rows that came from the departmental checklist, so
            // the control function catalogue never has to guess.
            $table->boolean('is_control_function')->default(false)->after('is_template');
            // §D.1 step 6: the natural key of an imported function is
            // (tenant, control unit, control entity, title). controls.unit_id
            // is the OPERATIONAL tree; this is the second-line sub-unit that
            // owns the function, and the two are not interchangeable.
            $table->foreignId('control_unit_id')->nullable()->after('unit_id')
                ->constrained('control_units')->nullOnDelete();
            $table->foreignId('control_entity_id')->nullable()->after('control_unit_id')
                ->constrained('control_entities')->nullOnDelete();

            $table->index(['tenant_id', 'is_control_function']);
            $table->index(['tenant_id', 'control_unit_id']);
        });

        // Backfill: every existing control keeps its enum wording as the
        // raw label, so nothing displays blank after the upgrade.
        DB::table('controls')->whereNull('frequency_raw')->update(['frequency_raw' => DB::raw('frequency')]);
    }

    public function down(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'control_unit_id']);
            $table->dropIndex(['tenant_id', 'is_control_function']);
            $table->dropConstrainedForeignId('control_entity_id');
            $table->dropConstrainedForeignId('control_unit_id');
            $table->dropColumn('is_control_function');
            $table->dropColumn('source_ref');
            $table->dropColumn('frequency_raw');
            $table->dropConstrainedForeignId('frequency_id');
        });
    }
};
