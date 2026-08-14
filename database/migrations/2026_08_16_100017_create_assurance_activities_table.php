<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One cell of the combined assurance map (17.4): a provider's coverage
     * of a subject — a risk, a control, an obligation or a process.
     *
     * The subject is addressed by alias + id rather than by four nullable
     * foreign keys, matching the linkage graph's convention, so a new
     * assurable subject type is a string rather than a migration.
     *
     * `reliance_level` is the decision a combined assurance report actually
     * turns on: how much the board may lean on this provider's work. It is
     * a named person's act, recorded with who made it and when, and it is
     * what travels over the /api/v1 interlock in both directions (17.5).
     */
    public function up(): void
    {
        Schema::create('assurance_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('assurance_providers')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('activity_name')->nullable();
            $table->enum('coverage_level', ['none', 'partial', 'full'])->default('partial');
            $table->enum('assurance_type', [
                'reasonable', 'limited', 'agreed_upon_procedures', 'review', 'inspection', 'self_assessment',
            ])->default('limited');
            $table->date('last_assurance_date')->nullable();
            $table->date('next_assurance_date')->nullable();
            $table->string('period_label', 40)->nullable();
            $table->enum('conclusion', ['satisfactory', 'needs_improvement', 'unsatisfactory', 'not_concluded'])
                ->default('not_concluded');
            $table->enum('reliance_level', ['none', 'limited', 'moderate', 'full'])->default('none');
            $table->text('reliance_rationale')->nullable();
            $table->foreignId('reliance_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reliance_recorded_at')->nullable();
            $table->text('scope_note')->nullable();
            // Where this row came from: maintained here, or received over
            // the integration layer from third line (17.5).
            $table->enum('source', ['manual', 'integration'])->default('manual');
            $table->string('external_ref')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider_id', 'subject_type', 'subject_id', 'period_label'], 'assurance_unique_per_period');
            $table->index('tenant_id');
            $table->index('provider_id');
            $table->index('entity_id');
            $table->index('reliance_recorded_by');
            $table->index(['subject_type', 'subject_id']);
            $table->index('coverage_level');
            $table->index('reliance_level');
            $table->index('source');
            $table->index('external_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assurance_activities');
    }
};
