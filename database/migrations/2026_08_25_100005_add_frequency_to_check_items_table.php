<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §C.2: line-level frequency. Seven of the client's functions
     * carry more than one rhythm across their own checklist lines —
     * NOSTRO is eleven daily lines and five monthly ones — so frequency
     * is not purely a property of the function. Without this the only
     * way to model it is to split one control into four, which breaks
     * the one-to-one with the document the bank signed off.
     *
     * NULL means inherit, which covers 1,483 of the 1,517 lines.
     */
    public function up(): void
    {
        Schema::table('check_items', function (Blueprint $table) {
            $table->foreignId('frequency_id')->nullable()->after('is_mandatory')
                ->constrained('control_frequencies')->nullOnDelete();
            $table->string('frequency_raw')->nullable()->after('frequency_id');
            // The originating workbook cell, e.g. HO!D412 — traceability
            // back to the document the client recognises.
            $table->string('source_ref', 40)->nullable()->after('frequency_raw');
        });
    }

    public function down(): void
    {
        Schema::table('check_items', function (Blueprint $table) {
            $table->dropColumn(['source_ref', 'frequency_raw']);
            $table->dropConstrainedForeignId('frequency_id');
        });
    }
};
