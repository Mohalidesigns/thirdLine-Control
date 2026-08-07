<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->enum('type', ['Head Office', 'Branch', 'Department'])->default('Department');
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_units');
    }
};
