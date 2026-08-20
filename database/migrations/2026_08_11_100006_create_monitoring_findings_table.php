<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One failing record from one run (12.3). record_data is redacted at
     * write time according to the dataset's pii_classification — the
     * finding is a control artefact, not a copy of the customer file
     * (12.7, R5).
     */
    public function up(): void
    {
        Schema::create('monitoring_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('monitoring_runs')->cascadeOnDelete();
            $table->string('record_identifier')->nullable();
            $table->json('record_data')->nullable();
            $table->text('failure_reason')->nullable();
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low'])->default('Medium');
            $table->enum('status', [
                'Open', 'Under Review', 'Confirmed', 'False Positive', 'Remediated', 'Accepted',
            ])->default('Open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('exception_id')->nullable()->constrained('control_exceptions')->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('run_id');
            $table->index('status');
            $table->index('severity');
            $table->index('reviewed_by');
            $table->index('exception_id');
            $table->index('incident_id');
            $table->index(['tenant_id', 'status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_findings');
    }
};
