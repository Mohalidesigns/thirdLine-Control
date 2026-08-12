<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The WhatsApp 24-hour customer-service window (15.3): a free-form
     * message is only allowed within the window opened by the recipient's
     * last inbound message; outside it, only a Meta-approved template may
     * be sent. The phone number is stored as a salted SHA-256 hash — enough
     * to answer "is this window open", never enough to recover the number.
     */
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('phone_hash', 64);
            $table->timestamp('last_inbound_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'phone_hash'], 'whatsapp_sessions_scope_unique');
            $table->index('tenant_id', 'whatsapp_sessions_tenant_idx');
            $table->index('last_inbound_at', 'whatsapp_sessions_inbound_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
    }
};
