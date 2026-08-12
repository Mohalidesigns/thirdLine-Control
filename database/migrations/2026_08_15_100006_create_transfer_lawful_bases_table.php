<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The lawful grounds a cross-border transfer may rest on (16.2). NDPA
     * Part VIII grounds are seeded data, not constants (R1), effective-dated
     * and tenant-overridable like every other regulatory record; anything
     * not confirmed against the gazetted Act carries
     * verification_status = 'unverified' (R10).
     */
    public function up(): void
    {
        Schema::create('transfer_lawful_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('legal_reference')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->enum('verification_status', ['verified', 'unverified', 'draft'])->default('unverified');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_lawful_bases');
    }
};
