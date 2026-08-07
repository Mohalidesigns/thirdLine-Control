<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            // NULL = global default; a tenant-scoped row overrides it.
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_feature_flags_tenant')->cascadeOnDelete();
            $table->string('key', 100);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedTinyInteger('rollout_percentage')->default(100);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('is_enabled');
            $table->unique(['tenant_id', 'key'], 'uq_feature_flags_tenant_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
