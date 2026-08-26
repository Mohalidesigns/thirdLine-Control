<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §D: the bank owns the checklist workbook and will revise it.
     * A one-off seeder that cannot be re-run against version 2 is a
     * liability, so every upload is a record: who ran it, against which
     * file (by hash), what it would change, and what it did change.
     *
     * The dry run writes an import row and its staged rows and nothing
     * else — the diff is reviewed before anybody commits.
     */
    public function up(): void
    {
        Schema::create('control_function_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            $table->string('source_name');
            $table->string('source_hash', 64)->nullable();
            $table->string('source_version', 20)->nullable();
            $table->enum('status', ['Dry Run', 'Committed', 'Failed', 'Discarded'])->default('Dry Run');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_unresolved')->default(0);
            $table->unsignedInteger('controls_added')->default(0);
            $table->unsignedInteger('controls_changed')->default(0);
            $table->unsignedInteger('items_added')->default(0);
            $table->unsignedInteger('items_changed')->default(0);
            $table->unsignedInteger('items_removed')->default(0);
            $table->unsignedInteger('scripts_versioned')->default(0);
            // Per-unit added/changed/removed/unresolved — the audit record
            // of what an operator was shown before they committed.
            $table->json('diff_report')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'reference']);
        });

        Schema::create('control_function_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_function_import_id')->constrained('control_function_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->string('sheet');
            $table->string('source_ref', 40)->nullable();
            // Exactly as written in the workbook, forward-filled but not
            // normalised — the round-trip export reads these back.
            $table->string('unit_raw');
            $table->text('function_raw');
            $table->text('checklist_raw');
            $table->string('frequency_raw')->nullable();
            $table->foreignId('frequency_id')->nullable()->constrained('control_frequencies')->nullOnDelete();
            // unresolved blocks the commit; the other four are the diff.
            $table->enum('resolution', ['added', 'unchanged', 'changed', 'removed', 'unresolved'])->default('unchanged');
            $table->string('message')->nullable();
            $table->foreignId('control_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->foreignId('check_item_id')->nullable()->constrained('check_items')->nullOnDelete();
            $table->timestamps();

            // Explicitly named: the derived name would be 72 characters
            // and MySQL caps an identifier at 64.
            $table->index(['control_function_import_id', 'resolution'], 'cfi_rows_import_resolution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_function_import_rows');
        Schema::dropIfExists('control_function_imports');
    }
};
