<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp message templates (15.3). Meta requires every out-of-session
     * message to use a pre-approved template, so approval_status mirrors
     * Meta's review state — only `approved` templates are ever sent.
     * tenant_id NULL rows are the platform catalogue; a tenant row with the
     * same key overrides it (the NotificationEvent pattern, R1).
     */
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name');
            $table->string('language', 10)->default('en');
            // Meta's template category: utility | authentication | marketing.
            $table->string('category', 30)->default('utility');
            $table->text('body');
            // Ordered placeholder names, e.g. ["first_name","due_date","link"].
            $table->json('variables')->nullable();
            $table->string('meta_template_id')->nullable();
            // draft → pending (submitted to Meta) → approved | rejected.
            $table->string('approval_status', 20)->default('draft');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'language'], 'whatsapp_templates_scope_unique');
            $table->index('tenant_id', 'whatsapp_templates_tenant_idx');
            $table->index('approval_status', 'whatsapp_templates_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
