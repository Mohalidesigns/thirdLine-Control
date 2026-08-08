<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csa_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')
                ->constrained('csa_questionnaires', indexName: 'fk_csa_questions_questionnaire')->cascadeOnDelete();
            $table->string('section')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->text('question_text');
            $table->text('help_text')->nullable();
            $table->enum('response_type', [
                'yes_no', 'yes_no_na', 'scale_1_5', 'maturity_0_5', 'single_select',
                'multi_select', 'free_text', 'numeric', 'date', 'file_upload',
            ])->default('yes_no');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('evidence_required')->default(false);
            $table->decimal('weight', 8, 2)->default(1);
            $table->foreignId('control_id')->nullable()
                ->constrained('controls', indexName: 'fk_csa_questions_control')->nullOnDelete();
            $table->foreignId('requirement_id')->nullable()
                ->constrained('framework_requirements', indexName: 'fk_csa_questions_requirement')->nullOnDelete();
            // e.g. {"in": ["No"]} — an answer matching raises an exception (9.3).
            $table->json('triggers_exception_on')->nullable();
            // Conditional visibility: {"question_id": 12, "in": ["Yes"]}.
            $table->json('condition')->nullable();
            $table->timestamps();

            $table->index('questionnaire_id');
            $table->index('control_id');
            $table->index('requirement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csa_questions');
    }
};
