<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a draft was rejected (Part C §C.3). A reason is mandatory on
     * reject in the review panel, and it lands here rather than in a free
     * text field nobody reads: grouped by issue_category on the governance
     * page, it is the evidence for the next prompt version.
     *
     * Kept separate from ai_interactions so feedback can be added later, and
     * more than once, without touching an append-only row.
     */
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interaction_id')->constrained('ai_interactions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('was_useful')->nullable();
            $table->enum('issue_category', [
                'incorrect', 'incomplete', 'irrelevant', 'unsafe', 'formatting', 'other',
            ])->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('interaction_id');
            $table->index('user_id');
            $table->index('issue_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};
