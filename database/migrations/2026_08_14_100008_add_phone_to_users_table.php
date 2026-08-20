<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp/SMS/USSD need somewhere to reach a user (15.3/15.4).
     * Nullable — a user with no phone simply never receives those
     * channels. E.164 expected; indexed for the USSD phone → user lookup.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->index('phone', 'users_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_phone_idx');
            $table->dropColumn('phone');
        });
    }
};
