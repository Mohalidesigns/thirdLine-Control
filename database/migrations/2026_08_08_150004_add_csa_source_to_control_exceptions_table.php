<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive enum extension (R8): a CSA answer matching its question's
     * triggers_exception_on raises an exception with source_type 'CSA'.
     */
    public function up(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', ['Test', 'Spot Check', 'Manual', 'CSA'])
                ->default('Manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', ['Test', 'Spot Check', 'Manual'])
                ->default('Manual')->change();
        });
    }
};
