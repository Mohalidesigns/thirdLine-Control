<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01 (R-H): the secure answer link for a respondent outside the app.
     * The plaintext token is shown once at generation and NEVER stored —
     * only its sha256 lands here, mirroring the internal audit product's
     * external_auditee_links. A token grants sight of ONE escalation and
     * the right to answer it; nothing else, ever.
     */
    public function up(): void
    {
        Schema::create('exception_response_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('escalation_id')->constrained('exception_escalations')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 8);
            $table->foreignId('created_by')->constrained('users', indexName: 'fk_exc_link_created_by');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_link_revoked_by')->nullOnDelete();
            $table->enum('status', ['active', 'used', 'revoked', 'expired'])->default('active');
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'escalation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_response_links');
    }
};
