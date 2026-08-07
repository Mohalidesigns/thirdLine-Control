<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frameworks', function (Blueprint $table) {
            $table->id();
            // NULL = system/global framework shipped in a content pack (R1);
            // a tenant row is that tenant's own or customised framework.
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_frameworks_tenant')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->string('issuing_body')->nullable();
            // ISO-3166 alpha-2, or 'INTL' for international frameworks.
            $table->string('jurisdiction', 8)->default('INTL');
            $table->string('version', 30)->default('1.0.0');
            $table->enum('category', [
                'internal_control', 'risk', 'security', 'privacy', 'governance',
                'financial', 'sustainability', 'sector',
            ])->default('internal_control');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('supersedes_framework_id')->nullable()
                ->constrained('frameworks', indexName: 'fk_frameworks_supersedes')->nullOnDelete();
            $table->boolean('is_certifiable')->default(false);
            $table->string('source_url', 500)->nullable();
            // R10: only 'verified' records may enter a generated submission.
            $table->enum('verification_status', ['verified', 'unverified', 'draft'])->default('unverified');
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('jurisdiction');
            $table->index('category');
            $table->index('verification_status');
            $table->index('is_active');
            $table->unique(['tenant_id', 'code', 'version'], 'uq_frameworks_tenant_code_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frameworks');
    }
};
