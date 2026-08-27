<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.4 — the Speak Up follow-up surface.
 *
 * A concern has to be workable and reportable-back-on WITHOUT opening the
 * investigation it may have produced. The two records are linked, but the
 * officer handling the submission is not thereby on the investigation
 * team, and the reporter must be answerable without anybody widening the
 * case file's allowlist.
 *
 * Three things the register could not previously record:
 *
 *  1. **The screening decision and why.** `cases` had a status and an
 *     investigation plan, but nowhere to say "we screened this on the 12th,
 *     decided it was a duplicate, and here is the reasoning". Without it
 *     the register cannot answer how long screening takes, which is the
 *     number a whistleblowing policy is actually judged on.
 *  2. **Follow-up as tracked work.** `cases.actions_taken` is one free-text
 *     column. Actions with an owner and a date are a list, and a list in a
 *     paragraph cannot be chased, counted or reported.
 *  3. **Acknowledgement to the reporter.** `case_notes.is_reporter_visible`
 *     already carries feedback both ways; what was missing is the record
 *     that the concern was ACKNOWLEDGED at all, and when — the first thing
 *     a reporter asks and the first thing a regulator checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            // ── Screening / triage ──────────────────────────────────────
            $table->text('triage_note')->nullable()->after('investigation_plan');
            $table->longText('triage_note_rich')->nullable()->after('triage_note');
            $table->enum('triage_decision', [
                'refer_to_investigation', 'handle_within_speak_up',
                'refer_externally', 'close_unsubstantiated', 'close_resolved', 'monitor',
            ])->nullable()->after('triage_note_rich');
            // dateTime, not timestamp: MySQL gives the first TIMESTAMP column
            // in a table an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('triaged_at')->nullable()->after('triage_decision');
            $table->foreignId('triaged_by')->nullable()->after('triaged_at')
                ->constrained('users', indexName: 'fk_cases_triaged_by')->nullOnDelete();

            // ── Acknowledgement to the reporter ─────────────────────────
            $table->dateTime('acknowledged_at')->nullable()->after('triaged_by');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')
                ->constrained('users', indexName: 'fk_cases_ack_by')->nullOnDelete();

            $table->index(['tenant_id', 'triaged_at'], 'cases_tenant_triaged_index');
        });

        /**
         * The follow-up log: what the control officer did about the concern,
         * who owns it, and whether it is finished. Deliberately NOT the
         * investigation's own consequence actions — a submission may be
         * followed up without an investigation ever being opened, and most
         * are.
         */
        Schema::create('case_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_case_follow_ups_tenant')->cascadeOnDelete();
            $table->foreignId('case_id')
                ->constrained('cases', indexName: 'fk_case_follow_ups_case')->cascadeOnDelete();

            $table->string('action');
            $table->text('detail')->nullable();
            $table->longText('detail_rich')->nullable();
            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_case_follow_ups_owner')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()
                ->constrained('users', indexName: 'fk_case_follow_ups_completer')->nullOnDelete();

            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_case_follow_ups_creator')->nullOnDelete();
            $table->timestamps();

            $table->index(['case_id', 'completed_at'], 'case_follow_ups_case_done_index');
            $table->index(['tenant_id', 'due_date'], 'case_follow_ups_tenant_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_follow_ups');

        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex('cases_tenant_triaged_index');
            $table->dropConstrainedForeignId('triaged_by');
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn([
                'triage_note', 'triage_note_rich', 'triage_decision',
                'triaged_at', 'acknowledged_at',
            ]);
        });
    }
};
