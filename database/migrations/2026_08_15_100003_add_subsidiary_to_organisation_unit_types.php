<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A banking group's subsidiary anchors its own subtree in the unit
     * hierarchy (16.1); 'Subsidiary' joins the type list additively —
     * every existing value remains valid (R8).
     */
    public function up(): void
    {
        Schema::table('organisation_units', function (Blueprint $table) {
            $table->enum('type', ['Head Office', 'Branch', 'Department', 'Subsidiary'])
                ->default('Department')->change();
        });
    }

    /**
     * Any unit already promoted to 'Subsidiary' has to come back to a value
     * the narrowed enum can hold, or MySQL truncates the column and the
     * rollback fails part-way through — which is what it did before this
     * was added. 'Department' is the column's own default and the safest
     * landing place.
     */
    public function down(): void
    {
        DB::table('organisation_units')
            ->where('type', 'Subsidiary')
            ->update(['type' => 'Department']);

        Schema::table('organisation_units', function (Blueprint $table) {
            $table->enum('type', ['Head Office', 'Branch', 'Department'])
                ->default('Department')->change();
        });
    }
};
