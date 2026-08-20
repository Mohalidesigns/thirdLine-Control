<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A generated, signed statement of where each data category is stored
     * and processed (16.2) — the artefact a customer hands to CBN, BoG or
     * NDPC. content is the full attestation body; checksum is its SHA-256;
     * signature is an HMAC over the checksum so tampering with a stored
     * attestation is detectable.
     */
    public function up(): void
    {
        Schema::create('residency_attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            $table->string('declared_country', 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('content');
            $table->string('checksum', 64);
            $table->string('signature', 64);
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('generated_by');
            $table->index('declared_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residency_attestations');
    }
};
