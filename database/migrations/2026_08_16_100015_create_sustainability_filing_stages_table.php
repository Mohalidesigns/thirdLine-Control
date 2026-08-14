<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per computed filing stage (17.3): its due date, whether it
     * has been filed, and what was filed. Materialised rather than derived
     * on read so a deadline that was communicated stays fixed even if the
     * offsets are later corrected — and so the compliance calendar and the
     * escalation sweep can read it like every other dated obligation.
     */
    public function up(): void
    {
        Schema::create('sustainability_filing_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('filing_id')->constrained('sustainability_filings')->cascadeOnDelete();
            $table->string('stage_key', 40);
            $table->string('label');
            $table->smallInteger('offset_months');
            $table->date('due_at');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submission_reference')->nullable();
            $table->foreignId('evidence_id')->nullable()->constrained('evidence')->nullOnDelete();
            $table->enum('status', ['Pending', 'In Progress', 'Submitted', 'Late', 'Waived'])->default('Pending');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['filing_id', 'stage_key']);
            $table->index('tenant_id');
            $table->index('submitted_by');
            $table->index('evidence_id');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sustainability_filing_stages');
    }
};
