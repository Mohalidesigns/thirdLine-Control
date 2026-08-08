<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The universal linkage graph (10.6). Polymorphic on both ends by alias
     * (risk, control, metric, incident, policy, obligation, objective,
     * document, exception) so later phases add node types without a
     * migration. Every edge is tenant-scoped and attributed.
     */
    public function up(): void
    {
        Schema::create('entity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->enum('relationship', [
                'mitigates', 'causes', 'indicates', 'governs', 'evidences',
                'relates_to', 'escalates_to', 'depends_on',
            ])->default('relates_to');
            $table->unsignedTinyInteger('strength')->default(3);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'source_type', 'source_id', 'target_type', 'target_id', 'relationship'],
                'entity_links_edge_unique'
            );
            $table->index('tenant_id');
            $table->index('created_by');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'entity_links_source_index');
            $table->index(['tenant_id', 'target_type', 'target_id'], 'entity_links_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_links');
    }
};
