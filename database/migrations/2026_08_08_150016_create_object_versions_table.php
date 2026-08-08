<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared version store (9.8): the ControlVersion pattern generalised.
     * Any model using HasVersions snapshots its versioned attributes here
     * on create and on every change, with attribution.
     */
    public function up(): void
    {
        Schema::create('object_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_object_versions_tenant')->cascadeOnDelete();
            $table->string('versionable_type');
            $table->unsignedBigInteger('versionable_id');
            $table->unsignedInteger('version_no');
            $table->json('payload');
            $table->string('change_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_object_versions_creator')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['versionable_type', 'versionable_id'], 'ix_object_versions_subject');
            $table->unique(['versionable_type', 'versionable_id', 'version_no'], 'uq_object_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_versions');
    }
};
