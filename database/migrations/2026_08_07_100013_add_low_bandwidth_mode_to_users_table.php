<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Phase 7.10: reduced-data mode for mid-range Android on 4G (R6).
            $table->boolean('low_bandwidth_mode')->default(false)->after('is_break_glass');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('low_bandwidth_mode');
        });
    }
};
