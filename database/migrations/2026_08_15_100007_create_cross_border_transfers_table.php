<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cross-border transfer register (16.2): what left the country, to
     * where, under which lawful basis, authorised by whom. lawful_basis_id
     * is NOT NULL by design — a transfer without a recorded lawful basis
     * cannot exist as a row, it is blocked in the service and at the
     * schema. Feeds the Phase 13 NDPC submission pack.
     */
    public function up(): void
    {
        Schema::create('cross_border_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('reference', 30);
            $table->enum('direction', ['outbound', 'inbound'])->default('outbound');
            $table->json('data_categories');
            $table->boolean('contains_personal_data')->default(true);
            $table->text('description');
            $table->string('destination_country', 2);
            $table->string('recipient');
            $table->foreignId('lawful_basis_id')->constrained('transfer_lawful_bases')->restrictOnDelete();
            // The NDPA Schedule adequacy note or contract reference behind
            // the chosen basis.
            $table->text('lawful_basis_note')->nullable();
            $table->unsignedInteger('volume_records')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorised_at')->nullable();
            $table->enum('status', ['pending_authorisation', 'authorised', 'completed'])->default('pending_authorisation');
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('lawful_basis_id');
            $table->index('recorded_by');
            $table->index('authorised_by');
            $table->index('status');
            $table->index('destination_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_border_transfers');
    }
};
