<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            // NULL = platform event catalogue (seeded, rule R1); a tenant
            // row overrides defaults for that tenant.
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_notification_events_tenant')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('category', 50)->default('general');
            $table->json('default_channels');
            $table->boolean('is_user_configurable')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('category');
            $table->unique(['tenant_id', 'key'], 'uq_notification_events_tenant_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
