<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    public function down(): void
    {
        Schema::table('organisation_units', function (Blueprint $table) {
            $table->enum('type', ['Head Office', 'Branch', 'Department'])
                ->default('Department')->change();
        });
    }
};
