<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('mfa_enforced_roles')->nullable()->after('fiscal_year_start_month');
            $table->unsignedSmallInteger('mfa_grace_period_days')->default(7)->after('mfa_enforced_roles');
            $table->timestamp('mfa_enforced_at')->nullable()->after('mfa_grace_period_days');
            // SMS OTP backup is opt-in (SIM-swap risk in-market, Phase 7.2).
            $table->boolean('mfa_sms_opt_in')->default(false)->after('mfa_enforced_at');
            // Break-glass local logins allowed per day (Phase 7.1).
            $table->unsignedSmallInteger('break_glass_daily_cap')->default(3)->after('mfa_sms_opt_in');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'mfa_enforced_roles', 'mfa_grace_period_days', 'mfa_enforced_at',
                'mfa_sms_opt_in', 'break_glass_daily_cap',
            ]);
        });
    }
};
