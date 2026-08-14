<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Initiatives (17.1): the projects that deliver an objective. Budget is
     * integer minor units plus an ISO-4217 code — never a float (R7).
     */
    public function up(): void
    {
        Schema::create('initiatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('objective_id')->nullable()->constrained('objectives')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('reference', 30);
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Proposed', 'Approved', 'In Progress', 'On Hold', 'Delivered', 'Cancelled'])
                ->default('Proposed');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->bigInteger('budget_minor')->nullable();
            $table->bigInteger('spend_minor')->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->enum('progress_mode', ['auto', 'manual'])->default('auto');
            $table->unsignedSmallInteger('weight')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('objective_id');
            $table->index('entity_id');
            $table->index('owner_id');
            $table->index('created_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('initiatives');
    }
};
