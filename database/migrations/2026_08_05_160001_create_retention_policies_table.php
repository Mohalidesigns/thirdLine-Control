<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('evidence_class');
            $table->unsignedInteger('retention_months');
            $table->enum('disposal_action', ['Delete', 'Anonymise'])->default('Delete');
            $table->boolean('requires_dual_approval')->default(true);
            $table->text('legal_basis_note')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
