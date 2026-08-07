<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_notification_preferences_tenant')->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_notification_preferences_user')->cascadeOnDelete();
            $table->string('event_key', 100);
            $table->enum('channel', ['in_app', 'email', 'whatsapp', 'sms', 'push']);
            $table->boolean('is_enabled')->default(true);
            $table->enum('digest_frequency', ['immediate', 'daily', 'weekly'])->default('immediate');
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('channel');
            $table->unique(['user_id', 'event_key', 'channel'], 'uq_notification_prefs_user_event_channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
