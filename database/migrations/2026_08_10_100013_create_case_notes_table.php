<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Investigation notes (11.4). Privileged notes are legal-advice working
     * papers: they are visible only to the lead investigator and the case
     * allowlist, and never enter a board extract.
     */
    public function up(): void
    {
        Schema::create('case_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_case_notes_tenant')->cascadeOnDelete();
            $table->foreignId('case_id')
                ->constrained('cases', indexName: 'fk_case_notes_case')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()
                ->constrained('users', indexName: 'fk_case_notes_author')->nullOnDelete();
            $table->text('note');
            $table->boolean('is_privileged')->default(false);
            $table->boolean('is_reporter_visible')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('case_id');
            $table->index('author_id');
            $table->index('is_privileged');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_notes');
    }
};
