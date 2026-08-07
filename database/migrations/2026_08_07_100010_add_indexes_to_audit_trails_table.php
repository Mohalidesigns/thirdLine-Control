<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Phase 7 audit explorer filters by tenant + date, and the
     * break-glass cap counts by tenant + action + day.
     */
    public function up(): void
    {
        Schema::table('audit_trails', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'ix_audit_trails_tenant_created');
            $table->index(['tenant_id', 'action', 'created_at'], 'ix_audit_trails_tenant_action_created');
        });
    }

    public function down(): void
    {
        Schema::table('audit_trails', function (Blueprint $table) {
            $table->dropIndex('ix_audit_trails_tenant_created');
            $table->dropIndex('ix_audit_trails_tenant_action_created');
        });
    }
};
