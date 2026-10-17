<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which board each role lands on at /dashboard. Configured from
     * Reporting → Dashboards, so the landing page itself carries no
     * configuration. One row per role per tenant; a role with no row falls
     * back to the seeded is_default_for_role_id, then the first visible board.
     */
    public function up(): void
    {
        Schema::create('dashboard_home_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'role_id']);
            $table->index('dashboard_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_home_assignments');
    }
};
