<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('password');           // encrypted cast
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at'); // encrypted array of hashes
            // Break-glass local login flag (Phase 7.1): the only accounts
            // that may use password login once SSO is enforced.
            $table->boolean('is_break_glass')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'is_break_glass']);
        });
    }
};
