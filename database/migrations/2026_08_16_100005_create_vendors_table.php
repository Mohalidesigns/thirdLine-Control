<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The third-party register (17.2).
     *
     * `regulator_notification_required` is a flag on the record, not a rule
     * in PHP: whether a given outsourcing is material enough to notify a
     * regulator is a decision the institution makes against its regulator's
     * own framework, and the obligation itself lives in the Phase 8
     * obligation register where its citation and deadline can be verified
     * (R1, R10). This column records the decision and the filing, not the
     * rule that produced it.
     *
     * `data_access_classification` drives the NDPA questions a due-diligence
     * questionnaire has to ask, and the sub-processor list is what a
     * fourth-party question is answered from.
     */
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('reference', 30);
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('category', 100)->nullable();
            $table->enum('criticality', ['Critical', 'High', 'Medium', 'Low'])->default('Medium');
            $table->text('services_provided')->nullable();
            $table->string('jurisdiction', 2)->default('NG');
            // Where the vendor actually processes the institution's data —
            // often not the same country it is incorporated in, and the
            // number the concentration view groups by (17.2).
            $table->string('processing_country', 2)->nullable();
            $table->date('relationship_start')->nullable();
            $table->date('relationship_end')->nullable();
            $table->bigInteger('annual_spend_minor')->nullable();
            $table->string('spend_currency', 3)->default('NGN');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('regulator_notification_required')->default(false);
            $table->date('regulator_notified_at')->nullable();
            $table->string('regulator_notification_ref')->nullable();
            $table->foreignId('notification_obligation_id')->nullable()
                ->constrained('regulatory_obligations')->nullOnDelete();
            $table->enum('data_access_classification', [
                'none', 'internal', 'confidential', 'personal', 'sensitive_personal',
            ])->default('none');
            $table->json('sub_processors')->nullable();
            $table->unsignedTinyInteger('review_frequency_months')->default(12);
            $table->enum('status', ['Onboarding', 'Active', 'Under Review', 'Suspended', 'Exited'])->default('Onboarding');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('owner_id');
            $table->index('created_by');
            $table->index('notification_obligation_id');
            $table->index('status');
            $table->index('criticality');
            $table->index(['tenant_id', 'jurisdiction']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
