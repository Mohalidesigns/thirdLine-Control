<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What due diligence found, and whether it was fixed (17.2).
     * Remediation reuses the Phase 9 improvement-action database rather
     * than growing a parallel tracker (R8).
     */
    public function up(): void
    {
        Schema::create('vendor_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('vendor_assessments')->nullOnDelete();
            $table->string('reference', 30);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low'])->default('Medium');
            $table->enum('status', ['Open', 'In Remediation', 'Accepted', 'Closed'])->default('Open');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->foreignId('improvement_action_id')->nullable()
                ->constrained('improvement_actions')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('vendor_id');
            $table->index('assessment_id');
            $table->index('owner_id');
            $table->index('closed_by');
            $table->index('raised_by');
            $table->index('improvement_action_id');
            $table->index('status');
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_findings');
    }
};
