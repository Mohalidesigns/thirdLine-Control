<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR2-A: cross-functional controls — a control whose risk does not
     * belong to one department carries stakeholder units. Exactly one row
     * per control may hold role=owner, and it must agree with
     * controls.unit_id (R-D) — ControlStructureService keeps the lockstep.
     */
    public function up(): void
    {
        Schema::create('control_stakeholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->constrained('controls')->cascadeOnDelete();
            $table->foreignId('organisation_unit_id')->constrained('organisation_units')->cascadeOnDelete();
            $table->enum('role', ['owner', 'co_owner', 'contributor', 'consulted']);
            // Optional named contact in that unit.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->longText('notes_rich')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'control_id', 'organisation_unit_id'], 'cs_tenant_control_unit_unique');
            $table->index(['tenant_id', 'organisation_unit_id', 'role'], 'cs_tenant_unit_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_stakeholders');
    }
};
