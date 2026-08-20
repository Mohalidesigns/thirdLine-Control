<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person (or service account) found holding both halves of a toxic
     * pair (12.3). subject_identifier is the source system's own id — a
     * staff number or UPN — not a SecondLine user, because the offending
     * account often has no SecondLine login at all.
     */
    public function up(): void
    {
        Schema::create('sod_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('sod_conflict_rules')->cascadeOnDelete();
            $table->string('subject_identifier');
            $table->string('subject_name')->nullable();
            $table->foreignId('entity_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->timestamp('detected_at')->nullable();
            $table->foreignId('snapshot_id')->nullable()->constrained('data_snapshots')->nullOnDelete();
            $table->enum('status', ['Open', 'Mitigated', 'Accepted', 'Remediated', 'False Positive'])->default('Open');
            $table->text('mitigation_notes')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('accepted_until')->nullable();
            $table->timestamp('remediated_at')->nullable();
            $table->foreignId('exception_id')->nullable()->constrained('control_exceptions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'rule_id', 'subject_identifier'], 'sod_violations_unique_subject');
            $table->index('tenant_id');
            $table->index('rule_id');
            $table->index('entity_id');
            $table->index('snapshot_id');
            $table->index('accepted_by');
            $table->index('exception_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sod_violations');
    }
};
