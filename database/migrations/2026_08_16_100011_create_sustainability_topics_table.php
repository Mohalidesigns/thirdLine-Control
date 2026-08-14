<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sustainability topic register (17.3). Rows with tenant_id NULL are
     * the shipped IFRS S1/S2 starting set; a tenant adds its own topics and
     * may override a shipped one.
     *
     * `verification_status` travels with every row exactly as it does on a
     * regulatory obligation: a topic, a disclosure requirement or a
     * cross-reference that has not been confirmed against the primary IFRS
     * or FRC document is 'unverified' and is excluded from anything that
     * gets filed (R10).
     */
    public function up(): void
    {
        Schema::create('sustainability_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('pillar', ['environmental', 'social', 'governance'])->default('environmental');
            // The standard and paragraph this topic answers to, e.g.
            // 'IFRS S2 §29(a)'. Free text because the citation format is the
            // standard-setter's, not ours (R1).
            $table->string('standard_reference')->nullable();
            $table->string('standard', 40)->nullable();
            $table->enum('verification_status', ['verified', 'unverified'])->default('unverified');
            $table->text('verification_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index('pillar');
            $table->index('verification_status');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sustainability_topics');
    }
};
