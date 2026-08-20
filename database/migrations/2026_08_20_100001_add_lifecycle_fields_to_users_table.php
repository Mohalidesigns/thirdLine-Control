<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User lifecycle for the admin Users area: invitation tracking (an
     * invited user has no self-chosen password yet), last login for the
     * list view, and the force-change flag for temporary passwords.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('is_active');
            $table->timestamp('invite_accepted_at')->nullable()->after('invited_at');
            $table->timestamp('last_login_at')->nullable()->after('invite_accepted_at');
            $table->boolean('must_change_password')->default(false)->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'invite_accepted_at', 'last_login_at', 'must_change_password']);
        });
    }
};
