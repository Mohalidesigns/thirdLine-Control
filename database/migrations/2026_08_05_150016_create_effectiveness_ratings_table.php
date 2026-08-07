<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('effectiveness_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->constrained()->cascadeOnDelete();
            $table->string('period_label');
            $table->enum('design_effectiveness', ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested']);
            $table->text('design_rationale')->nullable();
            $table->enum('operating_effectiveness', ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested']);
            $table->text('operating_rationale')->nullable();
            $table->enum('overall_rating', ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested']);
            $table->enum('status', ['Draft', 'Pending Approval', 'Published'])->default('Draft');
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['test_instance_id']);
            $table->index(['control_id', 'period_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effectiveness_ratings');
    }
};
