<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Governing artefacts (policies, procedures, charters…) — distinct
        // from Evidence, which is proof. Maker-checker approval; the
        // approver can never be the owner (9.6).
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_documents_tenant')->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()
                ->constrained('document_folders', indexName: 'fk_documents_folder')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('document_type', [
                'policy', 'procedure', 'standard', 'guideline', 'form', 'template',
                'charter', 'report', 'certificate', 'contract', 'other',
            ])->default('policy');
            $table->string('reference')->nullable();
            $table->unsignedInteger('version_no')->default(1);
            $table->enum('status', [
                'Draft', 'Under Review', 'Approved', 'Published', 'Superseded', 'Archived',
            ])->default('Draft');
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_documents_owner')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()
                ->constrained('users', indexName: 'fk_documents_approver')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->date('review_due_at')->nullable();
            $table->date('next_review_at')->nullable();
            $table->boolean('is_confidential')->default(false);
            $table->json('access_role_ids')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('supersedes_document_id')->nullable()
                ->constrained('documents', indexName: 'fk_documents_supersedes')->nullOnDelete();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('folder_id');
            $table->index('status');
            $table->index('document_type');
            $table->index('review_due_at');
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
