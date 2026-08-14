<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The FRC pre-reporting filing tracker (17.3).
     *
     * The three stage deadlines are computed from the entity's fiscal year
     * start plus an offset in months, and the offsets are **data**: they
     * live in `filing_stage_offsets` on the row so a tenant whose regulator
     * publishes different offsets, or whose roadmap moves, changes a record
     * rather than waiting for a deploy (R1). The seeded defaults carry
     * `verification_status = 'unverified'` until an officer confirms them
     * against the FRC's own published roadmap — the platform must never
     * present an unconfirmed deadline as authoritative (R10).
     *
     * `adoption_year` is likewise a column, not a constant: which reporting
     * class an entity falls in, and from which year, is a determination the
     * institution records here.
     */
    public function up(): void
    {
        Schema::create('sustainability_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignId('regulator_id')->nullable()->constrained('regulators')->nullOnDelete();
            $table->string('reference', 30);
            $table->enum('reporting_class', ['pie', 'sme', 'public_sector', 'other'])->default('pie');
            // The first reporting period this entity must comply for. Its
            // source is a column so an officer can cite it.
            $table->smallInteger('adoption_year')->nullable();
            $table->date('fiscal_year_start');
            $table->string('period_label', 40);
            // [{key, label, offset_months, description}] — the stage schedule
            // this filing was computed from. Copied onto the row at creation
            // so a later change to the tenant default does not silently
            // restate a deadline that was already communicated.
            $table->json('filing_stage_offsets');
            $table->enum('verification_status', ['verified', 'unverified'])->default('unverified');
            $table->text('verification_note')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Planned', 'In Progress', 'Complete', 'Cancelled'])->default('Planned');
            $table->foreignId('submission_pack_id')->nullable()->constrained('submission_packs')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('regulator_id');
            $table->index('owner_id');
            $table->index('submission_pack_id');
            $table->index('status');
            $table->index('fiscal_year_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sustainability_filings');
    }
};
