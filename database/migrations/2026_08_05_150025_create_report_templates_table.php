<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('report_type', ['Spot Check', 'Test Summary', 'Exception Register', 'Board Pack']);
            $table->json('sections')->nullable();
            $table->json('header_config')->nullable();
            $table->json('footer_config')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
