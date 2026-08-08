<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csa_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')
                ->constrained('csa_campaigns', indexName: 'fk_csa_questionnaires_campaign')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version_no')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('instructions')->nullable();
            $table->enum('scoring_method', ['none', 'weighted', 'maturity'])->default('none');
            $table->decimal('pass_threshold', 5, 2)->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csa_questionnaires');
    }
};
