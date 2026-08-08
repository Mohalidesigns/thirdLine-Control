<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A confirmed monitoring finding and an unmitigated SoD violation both
     * raise an exception through the existing ExceptionService (12.4), so
     * the register gains two more sources. Additive only — existing rows,
     * routes and the /api/v1 contract are untouched (R8).
     */
    public function up(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', [
                'Test', 'Spot Check', 'Manual', 'CSA', 'KRI', 'Incident', 'Complaint', 'Monitoring', 'SoD',
            ])->default('Manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', [
                'Test', 'Spot Check', 'Manual', 'CSA', 'KRI', 'Incident', 'Complaint',
            ])->default('Manual')->change();
        });
    }
};
