<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An immutable capture of one dataset at one moment — the evidence a
     * monitoring run was executed against (12.1). Snapshots inherit the
     * Phase 5 retention and legal-hold machinery: expires_at drives the
     * purge sweep and legal_hold blocks it absolutely (12.7).
     */
    public function up(): void
    {
        Schema::create('data_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained('data_source_datasets')->cascadeOnDelete();
            $table->string('snapshot_ref');
            $table->string('period_label', 60)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->enum('status', ['Capturing', 'Ready', 'Failed', 'Expired', 'Purged'])->default('Capturing');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->date('expires_at')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->string('legal_hold_reason')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'snapshot_ref']);
            $table->index('tenant_id');
            $table->index('dataset_id');
            $table->index('status');
            $table->index(['tenant_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_snapshots');
    }
};
