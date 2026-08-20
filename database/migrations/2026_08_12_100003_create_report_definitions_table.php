<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The report designer's document model (13.3). This EXTENDS
     * report_templates rather than replacing it (R8) — the Phase 4 PDF
     * paths keep resolving templates exactly as before, while a definition
     * adds sections, parameters, multiple output formats and an approval.
     *
     * regulator_id / obligation_id are set on regulatory definitions so a
     * submission pack can find the document that files it (13.4).
     */
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('report_type', ['operational', 'management', 'board', 'regulatory', 'ad_hoc'])
                ->default('operational');
            $table->json('output_formats')->nullable();
            $table->json('sections')->nullable();
            $table->json('parameters')->nullable();
            $table->json('styling')->nullable();
            $table->json('cover_page_config')->nullable();
            $table->json('header_config')->nullable();
            $table->json('footer_config')->nullable();
            $table->string('confidentiality', 40)->default('Internal');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('version_no')->default(1);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('regulator_id')->nullable()->constrained('regulators')->nullOnDelete();
            $table->foreignId('obligation_id')->nullable()->constrained('regulatory_obligations')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index('owner_id');
            $table->index('approved_by');
            $table->index('report_type');
            $table->index('regulator_id');
            $table->index('obligation_id');
            $table->index(['tenant_id', 'is_active', 'report_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
