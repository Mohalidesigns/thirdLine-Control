<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_script_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->text('question');
            $table->text('guidance')->nullable();
            $table->text('expected_result')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->enum('default_severity_on_fail', ['Critical', 'High', 'Medium', 'Low'])->default('Medium');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_items');
    }
};
