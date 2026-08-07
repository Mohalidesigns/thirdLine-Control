<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exception_id')->nullable()->constrained('control_exceptions')->cascadeOnDelete();
            $table->foreignId('test_instance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('matrix_id')->nullable()->constrained('escalation_matrices')->nullOnDelete();
            $table->unsignedTinyInteger('tier_no');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel');
            $table->timestamp('triggered_at');
            $table->enum('delivery_status', ['Pending', 'Sent', 'Failed'])->default('Pending');
            $table->string('payload_summary', 500)->nullable();
            $table->timestamps();

            $table->index(['exception_id', 'tier_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_events');
    }
};
