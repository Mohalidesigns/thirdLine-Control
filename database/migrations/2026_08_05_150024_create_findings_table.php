<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spot_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->text('observation');
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low'])->default('Medium');
            $table->text('risk_implication')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('management_response')->nullable();
            $table->text('agreed_action')->nullable();
            $table->foreignId('responsible_party_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
