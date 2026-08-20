<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR — legal hold on a case suspends the scheduled purge of its
     * reporter metadata. Setting or lifting a hold is a named, logged act
     * requiring the reveal-approver permission.
     */
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->boolean('legal_hold')->default(false)->after('closed_at');
            $table->string('legal_hold_reason', 500)->nullable()->after('legal_hold');
            $table->foreignId('legal_hold_by')->nullable()->after('legal_hold_reason')->constrained('users');
            $table->timestamp('legal_hold_at')->nullable()->after('legal_hold_by');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_hold_by');
            $table->dropColumn(['legal_hold', 'legal_hold_reason', 'legal_hold_at']);
        });
    }
};
