<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01 (R1): every acknowledgement and response clock in the Exception
     * Manager reads from this table — no day count lives in PHP. Offsets are
     * business days relative to the response due date (negative = before).
     */
    public function up(): void
    {
        Schema::create('exception_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low']);
            $table->unsignedInteger('acknowledge_within_hours');
            $table->unsignedInteger('respond_within_days');
            $table->json('reminder_offsets')->nullable();
            $table->unsignedInteger('max_reminders')->default(5);
            $table->unsignedInteger('escalate_after_days');
            $table->boolean('auto_escalate_to_matrix')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'severity', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_sla_policies');
    }
};
