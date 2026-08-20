<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The server side of the offline outbox (15.1). Every queued action a
     * device replays lands here exactly once: client_id is the idempotency
     * key the device generated when the user acted, so a retry after a
     * dropped response returns the recorded outcome instead of applying
     * the action twice. Rows are kept after processing — they are the
     * evidence of what a device did while offline.
     */
    public function up(): void
    {
        Schema::create('offline_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id');
            $table->string('action_type', 60);
            $table->json('payload');
            // applied | rejected | conflict
            $table->string('status', 20);
            $table->json('result')->nullable();
            $table->text('reason')->nullable();
            // When the user actually acted, per the device clock — the
            // business timestamp; created_at is merely when sync happened.
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_id'], 'offline_actions_idempotency_unique');
            $table->index('tenant_id', 'offline_actions_tenant_idx');
            $table->index('status', 'offline_actions_status_idx');
            $table->index('action_type', 'offline_actions_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_actions');
    }
};
