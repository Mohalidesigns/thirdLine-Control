<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->enum('source_type', ['Test', 'Spot Check', 'Manual'])->default('Manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('risk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('check_result_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('root_cause')->nullable();
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low'])->default('Medium');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_party_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_raised');
            $table->date('target_closure_date')->nullable();
            $table->enum('status', ['Open', 'Assigned', 'In Progress', 'Remediated', 'Verified-Closed', 'Risk Accepted'])->default('Open');
            $table->text('remediation_plan')->nullable();
            $table->timestamp('remediated_at')->nullable();
            $table->foreignId('remediated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_method')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('closure_notes')->nullable();
            $table->text('risk_acceptance_reason')->nullable();
            $table->foreignId('risk_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('risk_acceptance_expiry')->nullable();
            $table->unsignedInteger('age_days')->default(0);
            $table->boolean('is_overdue')->default(false);
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('recurrence_of_exception_id')->nullable()->constrained('control_exceptions')->nullOnDelete();
            $table->unsignedInteger('extension_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['tenant_id', 'is_overdue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_exceptions');
    }
};
