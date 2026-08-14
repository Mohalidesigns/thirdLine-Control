<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materiality assessment (17.3). IFRS S1 requires financial materiality;
     * double materiality (impact as well as financial) is an election a
     * tenant makes, so both scores are columns and `basis` records which
     * test was actually applied — the platform never assumes an institution
     * has elected double materiality, and never assumes it has not.
     *
     * Determining a topic material is a second person's act: the assessor
     * cannot be the approver (R2), because a topic quietly assessed
     * immaterial by one person is a disclosure that never gets made.
     */
    public function up(): void
    {
        Schema::create('materiality_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignId('topic_id')->constrained('sustainability_topics')->cascadeOnDelete();
            $table->string('period_label', 40);
            $table->enum('basis', ['financial', 'double'])->default('financial');
            $table->unsignedTinyInteger('financial_materiality_score')->nullable();
            $table->unsignedTinyInteger('impact_materiality_score')->nullable();
            $table->boolean('is_material')->default(false);
            $table->text('rationale')->nullable();
            $table->json('stakeholders_consulted')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['Draft', 'Submitted', 'Approved', 'Rejected'])->default('Draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'topic_id', 'period_label', 'entity_id'], 'materiality_unique_per_period');
            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('topic_id');
            $table->index('assessed_by');
            $table->index('approved_by');
            $table->index('status');
            $table->index('is_material');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiality_assessments');
    }
};
