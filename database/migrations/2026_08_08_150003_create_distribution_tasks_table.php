<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')
                ->constrained('control_distributions', indexName: 'fk_distribution_tasks_distribution')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_distribution_tasks_owner')->nullOnDelete();
            $table->date('due_at')->nullable();
            $table->enum('status', ['Open', 'In Progress', 'Complete', 'Blocked'])->default('Open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()
                ->constrained('users', indexName: 'fk_distribution_tasks_completer')->nullOnDelete();
            $table->boolean('evidence_required')->default(false);
            $table->text('blocker_reason')->nullable();
            $table->timestamps();

            $table->index('distribution_id');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_tasks');
    }
};
