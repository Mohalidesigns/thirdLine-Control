<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dashboard is a saved arrangement of widgets (13.2). The arrangement
     * is data — a tenant composes its own board pack view without a
     * deployment (R1) — and visibility is explicit rather than inherited,
     * because a dashboard aggregates numbers a viewer may not be entitled
     * to see one by one.
     *
     * public_token exists only for visibility = public_link. It is a
     * high-entropy value, never the slug, so guessing a URL cannot leak a
     * board dashboard.
     */
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('visibility', ['private', 'role', 'tenant', 'public_link'])->default('private');
            $table->json('role_ids')->nullable();
            $table->string('public_token', 64)->nullable()->unique();
            $table->json('layout')->nullable();
            $table->unsignedInteger('refresh_interval_seconds')->default(0);
            $table->foreignId('is_default_for_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
            $table->index('owner_id');
            $table->index('visibility');
            $table->index('is_default_for_role_id');
            $table->index(['tenant_id', 'is_system', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboards');
    }
};
