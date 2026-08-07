<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('target_system', ['ThirdLine', 'NexusRisk']);
            $table->string('base_url')->nullable();
            $table->enum('auth_type', ['api_key', 'oauth2'])->default('api_key');
            $table->text('credentials_encrypted')->nullable();
            $table->string('inbound_key_hash')->nullable();
            $table->enum('sync_direction', ['Push', 'Pull', 'Bidirectional'])->default('Push');
            $table->enum('master_system', ['SecondLine', 'ThirdLine', 'PerCategory'])->default('SecondLine');
            $table->string('conflict_rule')->default('last_approved_at_wins');
            $table->json('entities_enabled')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'target_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_configs');
    }
};
