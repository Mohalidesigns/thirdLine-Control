<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('control_ref');
            $table->string('external_ref')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->enum('type', ['Preventive', 'Detective', 'Corrective']);
            $table->enum('nature', ['Manual', 'Automated', 'Hybrid', 'IT-Dependent Manual']);
            $table->foreignId('category_id')->nullable()->constrained('control_categories')->nullOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('business_processes')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('frequency', ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Semi-annual', 'Annual', 'Event-driven'])->default('Monthly');
            $table->boolean('is_key_control')->default(false);
            $table->json('framework_refs')->nullable();
            $table->enum('status', ['Draft', 'Pending Approval', 'Active', 'Under Review', 'Retired'])->default('Draft');
            $table->unsignedInteger('current_version')->default(1);
            $table->boolean('is_template')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('sync_status')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'control_ref']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controls');
    }
};
