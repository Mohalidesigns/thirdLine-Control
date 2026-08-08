<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained('risk_treatments')->cascadeOnDelete();
            $table->string('title');
            $table->date('due_at')->nullable();
            $table->enum('status', ['Pending', 'In Progress', 'Completed', 'Overdue', 'Cancelled'])->default('Pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('treatment_id');
            $table->index('owner_id');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_milestones');
    }
};
