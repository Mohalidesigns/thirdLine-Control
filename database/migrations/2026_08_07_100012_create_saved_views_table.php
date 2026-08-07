<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_saved_views_tenant')->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_saved_views_user')->cascadeOnDelete();
            $table->string('resource', 50);      // e.g. 'controls', 'exceptions'
            $table->string('name', 100);
            $table->json('filters');
            $table->json('columns')->nullable();
            $table->json('sort')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['user_id', 'resource']);
            $table->index(['tenant_id', 'resource', 'is_shared'], 'ix_saved_views_shared_lookup');
            $table->unique(['user_id', 'resource', 'name'], 'uq_saved_views_user_resource_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
