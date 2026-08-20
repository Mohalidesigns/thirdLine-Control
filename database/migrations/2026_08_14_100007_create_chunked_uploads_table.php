<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resumable chunked uploads (15.2) for evidence over 2MB on unreliable
     * connections. Chunks append to a temp file on the evidence disk; on
     * completion the assembled file goes through EvidenceService::store
     * exactly like a direct upload — same PII declaration, same retention
     * policy resolution, same audit trail.
     */
    public function up(): void
    {
        Schema::create('chunked_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('chunks_received')->default(0);
            // pending | complete | aborted
            $table->string('status', 20)->default('pending');
            $table->string('temp_path')->nullable();
            // Evidence linkage + PII declaration, applied on completion.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'chunked_uploads_tenant_idx');
            $table->index('user_id', 'chunked_uploads_user_idx');
            $table->index('status', 'chunked_uploads_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunked_uploads');
    }
};
