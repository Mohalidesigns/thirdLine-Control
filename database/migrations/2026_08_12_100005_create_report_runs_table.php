<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every generation, scheduled or on demand, leaves a row here (13.3).
     * checksum is what makes a distributed document provable months later:
     * the file on the regulator's desk either hashes to this value or it is
     * not the file this system produced.
     *
     * expires_at drives the secure download link — a board pack link that
     * never expires is a leak waiting for a forwarded email.
     */
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('report_schedules')->nullOnDelete();
            $table->string('run_ref', 40);
            $table->json('parameters')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['Queued', 'Running', 'Completed', 'Failed', 'Expired'])->default('Queued');
            $table->string('output_path')->nullable();
            $table->string('output_format', 12)->default('pdf');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('distributed_to')->nullable();
            $table->text('error_message')->nullable();
            $table->string('download_token', 64)->nullable()->unique();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'run_ref']);
            $table->index('tenant_id');
            $table->index('report_definition_id');
            $table->index('schedule_id');
            $table->index('requested_by');
            $table->index('status');
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
