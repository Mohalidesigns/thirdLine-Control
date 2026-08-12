<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web Push (VAPID) subscriptions per user/device (15.5). endpoint_hash
     * exists because push endpoints exceed index length limits — uniqueness
     * is enforced on the hash.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique('push_subscriptions_endpoint_unique');
            $table->string('p256dh')->nullable();
            $table->string('auth')->nullable();
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'push_subscriptions_tenant_idx');
            $table->index('user_id', 'push_subscriptions_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
