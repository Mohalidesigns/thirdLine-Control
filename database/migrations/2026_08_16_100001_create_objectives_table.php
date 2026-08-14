<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strategic objectives (17.1). The balanced-scorecard perspective is a
     * column rather than a table because the four Kaplan/Norton
     * perspectives plus sustainability are the axis the whole scorecard
     * view is drawn on; the objectives themselves are entirely a tenant's
     * own words.
     *
     * `progress_mode` decides who owns the number: 'auto' rolls it up from
     * child objectives, linked metrics and initiatives (ObjectiveService),
     * 'manual' means an owner asserts it and the roll-up is shown beside it
     * as the derived comparison. Nothing is hard-coded about the weighting
     * — every measure carries its own weight.
     */
    public function up(): void
    {
        Schema::create('objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignId('parent_objective_id')->nullable()->constrained('objectives')->nullOnDelete();
            $table->enum('perspective', [
                'financial', 'customer', 'internal_process', 'learning_growth', 'sustainability',
            ])->default('financial');
            $table->string('code', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // The planning period this objective belongs to, e.g. 'FY2026'
            // or '2026-2028'. A free string: a tenant's planning cycle is
            // not the platform's business.
            $table->string('period', 40)->nullable();
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->enum('status', ['Draft', 'Active', 'At Risk', 'Achieved', 'Missed', 'Cancelled'])
                ->default('Draft');
            // Nullable, and null means "not measured" rather than "0%". An
            // objective nobody has attached a measure or an initiative to is
            // unknown, not failing, and a board scorecard that showed it as
            // zero would be misrepresenting it.
            $table->unsignedTinyInteger('progress')->nullable();
            $table->enum('progress_mode', ['auto', 'manual'])->default('auto');
            // Relative weight inside the parent objective (or inside the
            // perspective, for a top-level objective).
            $table->unsignedSmallInteger('weight')->default(1);
            $table->timestamp('progress_calculated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('parent_objective_id');
            $table->index('owner_id');
            $table->index('created_by');
            $table->index('status');
            $table->index(['tenant_id', 'perspective']);
            $table->index(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objectives');
    }
};
