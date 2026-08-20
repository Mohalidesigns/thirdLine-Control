<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unattended generation and distribution (13.3). The cron expression and
     * the timezone are stored together because "07:00 on the first working
     * day" means Africa/Lagos to a Lagos bank and something else to its
     * London parent (R7).
     */
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('cron', 100);
            $table->string('timezone', 64)->default('Africa/Lagos');
            $table->json('parameters')->nullable();
            $table->string('output_format', 12)->default('pdf');
            $table->json('recipients')->nullable();
            $table->enum('delivery_method', ['email', 'in_app', 'sftp', 'api', 'download_only'])
                ->default('in_app');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('report_definition_id');
            $table->index('created_by');
            $table->index('is_active');
            $table->index('last_status');
            $table->index(['tenant_id', 'is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
