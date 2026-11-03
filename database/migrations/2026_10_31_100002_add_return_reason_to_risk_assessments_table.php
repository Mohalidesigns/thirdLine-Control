<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why the second-line reviewer returned an assessment, and who did, kept
     * on the assessment so the assessor sees it in the history where they
     * revise it. approved_by/approved_at now only ever mean a publish.
     */
    public function up(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table) {
            $table->text('return_reason')->nullable()->after('approved_at');
            $table->foreignId('returned_by')->nullable()->after('return_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
        });

        // Assessments already returned recorded the reviewer as approver and
        // the reason only in the audit trail: move both onto the new columns.
        DB::table('risk_assessments')->where('status', 'Rejected')->orderBy('id')->each(function ($row) {
            $audit = DB::table('audit_trails')
                ->where('entity_type', 'App\\Models\\RiskAssessment')
                ->where('entity_id', $row->id)
                ->where('action', 'rejected')
                ->latest('id')
                ->first();

            DB::table('risk_assessments')->where('id', $row->id)->update([
                'return_reason' => $audit ? (json_decode($audit->after, true)['reason'] ?? null) : null,
                'returned_by' => $row->approved_by,
                'returned_at' => $row->approved_at,
                'approved_by' => null,
                'approved_at' => null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['return_reason', 'returned_at']);
        });
    }
};
