<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The complaint handling trail (11.3). is_customer_visible marks the
     * entries that may appear on a customer-facing status view — internal
     * deliberation never leaks into a tracking-ID lookup.
     */
    public function up(): void
    {
        Schema::create('complaint_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_complaint_activities_tenant')->cascadeOnDelete();
            $table->foreignId('complaint_id')
                ->constrained('complaints', indexName: 'fk_complaint_activities_complaint')->cascadeOnDelete();
            // dateTime, as elsewhere in this phase: a NOT NULL TIMESTAMP
            // would inherit MySQL's implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('occurred_at');
            $table->foreignId('actor_id')->nullable()
                ->constrained('users', indexName: 'fk_complaint_activities_actor')->nullOnDelete();
            $table->string('activity_type', 60);
            $table->text('description')->nullable();
            $table->string('channel', 40)->nullable();
            $table->boolean('is_customer_visible')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('complaint_id');
            $table->index('actor_id');
            $table->index(['complaint_id', 'occurred_at'], 'idx_complaint_activities_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_activities');
    }
};
