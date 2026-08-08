<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Board-approved appetite statements (10.3). Versioned and effective-
     * dated: a change supersedes rather than edits, so the position the
     * board approved on a date is always recoverable.
     */
    public function up(): void
    {
        Schema::create('risk_appetites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->foreignId('risk_category_id')->nullable()->constrained('risk_categories')->nullOnDelete();
            $table->text('statement');
            $table->enum('appetite_level', ['Averse', 'Minimal', 'Cautious', 'Open', 'Hungry'])->default('Cautious');
            $table->decimal('tolerance_upper', 10, 2)->nullable();
            $table->decimal('tolerance_lower', 10, 2)->nullable();
            $table->decimal('capacity', 10, 2)->nullable();
            $table->text('metric_definition')->nullable();
            $table->unsignedInteger('version_no')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('risk_appetites')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->date('review_due_at')->nullable();
            $table->enum('status', ['Draft', 'Pending Approval', 'Active', 'Superseded'])->default('Draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('risk_category_id');
            $table->index('status');
            $table->index(['tenant_id', 'status', 'risk_category_id'], 'appetite_active_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_appetites');
    }
};
