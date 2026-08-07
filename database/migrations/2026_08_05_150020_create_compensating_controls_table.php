<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensating_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exception_id')->nullable()->constrained('control_exceptions')->nullOnDelete();
            $table->foreignId('primary_control_id')->constrained('controls')->cascadeOnDelete();
            $table->text('description');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_temporary')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('residual_exposure_note')->nullable();
            $table->enum('status', ['Proposed', 'Approved', 'Active', 'Expired', 'Withdrawn'])->default('Proposed');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('linked_control_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensating_controls');
    }
};
