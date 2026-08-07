<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable overall-rating derivation matrix (FR-7.4). Seeded with
        // the BRD §7.3 defaults; tenants may override their own rows.
        Schema::create('rating_matrix_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('design_effectiveness', ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested']);
            $table->enum('operating_effectiveness', ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested']);
            $table->enum('overall_rating', ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested']);
            $table->timestamps();

            $table->unique(['tenant_id', 'design_effectiveness', 'operating_effectiveness'], 'rating_matrix_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_matrix_entries');
    }
};
