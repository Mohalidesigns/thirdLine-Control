<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('documents', indexName: 'fk_document_versions_document')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_document_versions_creator')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_document_versions_approver')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('document_id');
            $table->unique(['document_id', 'version_no'], 'uq_document_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
