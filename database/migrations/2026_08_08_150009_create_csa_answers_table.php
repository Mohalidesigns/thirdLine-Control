<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csa_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')
                ->constrained('csa_responses', indexName: 'fk_csa_answers_response')->cascadeOnDelete();
            $table->foreignId('question_id')
                ->constrained('csa_questions', indexName: 'fk_csa_answers_question')->cascadeOnDelete();
            $table->json('answer_value')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('evidence_id')->nullable()
                ->constrained('evidence', indexName: 'fk_csa_answers_evidence')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index('response_id');
            $table->index('question_id');
            $table->unique(['response_id', 'question_id'], 'uq_csa_answer_per_question');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csa_answers');
    }
};
