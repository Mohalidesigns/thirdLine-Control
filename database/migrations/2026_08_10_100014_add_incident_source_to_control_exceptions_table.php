<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An incident that names a failed control auto-raises an exception
     * against it (11.2), so the exception register gains two more sources
     * alongside Test, Spot Check, Manual, CSA and KRI. Additive only —
     * existing rows and API contracts are untouched (R8).
     */
    public function up(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', ['Test', 'Spot Check', 'Manual', 'CSA', 'KRI', 'Incident', 'Complaint'])
                ->default('Manual')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', ['Test', 'Spot Check', 'Manual', 'CSA', 'KRI'])
                ->default('Manual')
                ->change();
        });
    }
};
