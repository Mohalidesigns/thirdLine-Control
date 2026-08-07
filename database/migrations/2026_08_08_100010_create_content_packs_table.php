<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Installation ledger for versioned, checksummed regulatory content.
        Schema::create('content_packs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60);
            $table->string('name');
            $table->string('jurisdiction', 8)->default('INTL');
            $table->string('version', 30);
            $table->string('checksum', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->foreignId('installed_by')->nullable()
                ->constrained('users', indexName: 'fk_content_packs_installer')->nullOnDelete();
            $table->text('changelog')->nullable();
            $table->json('verification_summary')->nullable();
            $table->timestamps();

            $table->index('jurisdiction');
            $table->index('installed_at');
            $table->unique(['code', 'version'], 'uq_content_packs_code_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_packs');
    }
};
