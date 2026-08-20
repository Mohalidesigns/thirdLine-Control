<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Milestones roll an initiative's progress up when it is in 'auto' mode (17.1). */
    public function up(): void
    {
        Schema::create('initiative_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiative_id')->constrained('initiatives')->cascadeOnDelete();
            $table->string('title');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Pending', 'In Progress', 'Complete', 'Missed'])->default('Pending');
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('initiative_id');
            $table->index('completed_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('initiative_milestones');
    }
};
