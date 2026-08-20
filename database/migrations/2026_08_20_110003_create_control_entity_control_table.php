<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR2-A: which controls a control entity oversees. A control may sit
     * under MANY entities (ATM lives in Information Systems Control and
     * under every branch) — this pivot is how every register, test,
     * exception and trend view scopes to the structure.
     */
    public function up(): void
    {
        Schema::create('control_entity_control', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_entity_id')->constrained('control_entities')->cascadeOnDelete();
            $table->foreignId('control_id')->constrained('controls')->cascadeOnDelete();
            $table->boolean('is_key')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'control_entity_id', 'control_id'], 'cec_tenant_entity_control_unique');
            $table->index(['tenant_id', 'control_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_entity_control');
    }
};
