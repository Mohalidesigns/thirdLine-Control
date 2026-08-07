<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_matrices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low']);
            $table->unsignedTinyInteger('tier_no');
            $table->enum('trigger_condition', [
                'exception_unassigned', 'exception_overdue', 'exception_inactive',
                'test_overdue', 'extension_pending',
            ]);
            $table->unsignedInteger('days_threshold');
            $table->string('recipient_role');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('channel', ['in_app', 'email', 'both'])->default('both');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'severity', 'trigger_condition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_matrices');
    }
};
