<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-message delivery log for WhatsApp, SMS and Web Push (15.3/15.4):
     * delivery receipts, per-tenant cost tracking (minor units + ISO
     * currency, R7) and the audit answer to "was the reminder actually
     * sent". recipient is stored MASKED (last three digits) — a message log
     * must never become a phone-number directory, and an anonymised
     * whistleblowing inbound stores no recipient at all.
     */
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);                    // whatsapp | sms | push | ussd
            $table->string('direction', 10)->default('out');  // out | in
            $table->string('template_key', 80)->nullable();
            $table->string('recipient_masked', 40)->nullable();
            // queued | sent | delivered | read | failed | skipped | anonymised
            $table->string('status', 20)->default('queued');
            $table->string('window', 10)->nullable();         // template | session (WhatsApp)
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('segments')->default(1);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('cost_currency', 3)->nullable();
            $table->boolean('was_anonymised')->default(false);
            $table->json('meta')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'message_logs_tenant_idx');
            $table->index('status', 'message_logs_status_idx');
            $table->index(['channel', 'created_at'], 'message_logs_channel_idx');
            $table->index('provider_message_id', 'message_logs_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};
