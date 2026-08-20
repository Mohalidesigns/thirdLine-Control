<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit per-entity access grants (16.1 business rule): a group-level
     * user sees a subsidiary's data only where a named person granted it.
     * Absence of a row is denial — there is no implicit group-wide read
     * and no admin bypass (R2).
     */
    public function up(): void
    {
        Schema::create('entity_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'user_id']);
            $table->index('user_id');
            $table->index('granted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_user');
    }
};
