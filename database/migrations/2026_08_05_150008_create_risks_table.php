<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('external_ref')->nullable();
            $table->string('code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->unsignedTinyInteger('inherent_likelihood')->default(3);
            $table->unsignedTinyInteger('inherent_impact')->default(3);
            $table->unsignedTinyInteger('inherent_rating')->default(9);
            $table->decimal('residual_rating', 5, 2)->nullable();
            $table->foreignId('risk_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['Local', 'NexusRisk'])->default('Local');
            $table->enum('status', ['active', 'retired'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risks');
    }
};
