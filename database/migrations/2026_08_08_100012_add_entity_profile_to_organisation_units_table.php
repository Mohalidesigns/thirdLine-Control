<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive (R8): the obligation applicability engine matches an
     * obligation's applies_to against the entity's regulatory profile.
     */
    public function up(): void
    {
        Schema::table('organisation_units', function (Blueprint $table) {
            $table->boolean('is_legal_entity')->default(false);
            // e.g. commercial_bank, payment_service_provider, insurer,
            // pension_operator, listed_company — seeded reference data.
            $table->string('entity_type', 60)->nullable();
            $table->json('licence_categories')->nullable();
            $table->string('jurisdiction', 8)->nullable();
            $table->string('rc_number', 40)->nullable();
            $table->unsignedTinyInteger('fiscal_year_end_month')->nullable();
            $table->unsignedTinyInteger('fiscal_year_end_day')->nullable();
            $table->date('incorporated_on')->nullable();

            $table->index('entity_type');
            $table->index('is_legal_entity');
        });
    }

    public function down(): void
    {
        Schema::table('organisation_units', function (Blueprint $table) {
            $table->dropIndex(['entity_type']);
            $table->dropIndex(['is_legal_entity']);
            $table->dropColumn([
                'is_legal_entity', 'entity_type', 'licence_categories', 'jurisdiction',
                'rc_number', 'fiscal_year_end_month', 'fiscal_year_end_day', 'incorporated_on',
            ]);
        });
    }
};
