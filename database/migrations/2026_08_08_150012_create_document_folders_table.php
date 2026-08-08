<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_document_folders_tenant')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('document_folders', indexName: 'fk_document_folders_parent')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('path')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('parent_id');
            $table->unique(['tenant_id', 'parent_id', 'name'], 'uq_document_folder_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_folders');
    }
};
