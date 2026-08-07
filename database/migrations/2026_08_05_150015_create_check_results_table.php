<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_item_id')->constrained()->cascadeOnDelete();
            $table->enum('result', ['Pass', 'Fail', 'NA'])->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('tested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tested_at')->nullable();
            $table->timestamps();

            $table->unique(['test_instance_id', 'check_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_results');
    }
};
