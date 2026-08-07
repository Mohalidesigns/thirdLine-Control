<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('locale', 12)->default('en-NG')->after('settings');
            $table->string('timezone', 64)->default('Africa/Lagos')->after('locale');
            $table->char('currency', 3)->default('NGN')->after('timezone');
            $table->string('date_format', 20)->default('DD MMM YYYY')->after('currency');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1)->after('date_format');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['locale', 'timezone', 'currency', 'date_format', 'fiscal_year_start_month']);
        });
    }
};
