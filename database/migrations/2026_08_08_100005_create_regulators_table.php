<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global reference data shipped in content packs (R1) — no tenant_id.
        Schema::create('regulators', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('jurisdiction', 8)->default('NG');
            $table->json('sector')->nullable();
            $table->string('website', 500)->nullable();
            $table->string('portal_url', 500)->nullable();
            $table->json('contact_details')->nullable();
            $table->string('feed_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('jurisdiction');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulators');
    }
};
