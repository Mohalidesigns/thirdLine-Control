<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01: DENORMALISED CACHES maintained by ExceptionEscalationService —
     * never the source of truth; the exception_escalations rows are.
     * Additive (R8), with a lossless down().
     */
    public function up(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('escalation_status', [
                'None', 'Issued', 'Awaiting Response', 'Response Under Review',
                'Response Accepted', 'Response Rejected', 'All Closed',
            ])->default('None')->after('status');
            $table->timestamp('first_escalated_at')->nullable()->after('escalation_status');
            $table->timestamp('last_response_at')->nullable()->after('first_escalated_at');
            $table->unsignedInteger('open_escalation_count')->default(0)->after('last_response_at');
            $table->boolean('is_response_overdue')->default(false)->after('open_escalation_count');
            $table->enum('closure_type', [
                'Remediated', 'Risk Accepted', 'Superseded', 'No Longer Applicable',
            ])->nullable()->after('is_response_overdue');
        });
    }

    public function down(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->dropColumn([
                'escalation_status', 'first_escalated_at', 'last_response_at',
                'open_escalation_count', 'is_response_overdue', 'closure_type',
            ]);
        });
    }
};
