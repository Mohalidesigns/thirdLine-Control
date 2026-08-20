<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS templates (15.4). Same catalogue/override shape as
     * whatsapp_templates but with no external approval cycle — local
     * aggregators accept free text; DND sender-ID registration is an
     * account-level concern, not per template.
     */
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name');
            $table->text('body');
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'sms_templates_scope_unique');
            $table->index('tenant_id', 'sms_templates_tenant_idx');
            $table->index('is_active', 'sms_templates_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
