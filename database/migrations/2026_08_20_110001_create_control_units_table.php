<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR2-A: the sub-units of the Internal Control function (Head Office
     * Control, Information Systems Control, Branch Control, …). Behaviour
     * switches on `domain`, never on the name string (R-B) — a tenant may
     * rename or add units freely.
     */
    public function up(): void
    {
        Schema::create('control_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('domain', ['head_office', 'information_systems', 'branch', 'other'])->default('other');
            $table->text('description')->nullable();
            $table->longText('description_rich')->nullable();
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_units');
    }
};
