<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->nullable()->constrained('integration_configs')->nullOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('local_id')->nullable();
            $table->string('external_ref')->nullable();
            $table->enum('direction', ['outbound', 'inbound']);
            $table->string('idempotency_key')->nullable()->index();
            $table->json('payload')->nullable();
            $table->enum('status', ['Pending', 'Success', 'Failed', 'Retrying'])->default('Pending');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->foreignId('replayed_from_id')->nullable()->constrained('integration_sync_logs')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['entity_type', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_logs');
    }
};
