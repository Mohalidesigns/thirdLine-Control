<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A registered system SecondLine may extract from (12.1). The vendor
     * list is a column rather than a class hierarchy so adding a bank's
     * core-banking flavour is configuration, not a deployment (R1).
     *
     * Connection details and credentials are encrypted at rest and never
     * leave the server — the model hides both and the frontend receives
     * masked values only (R9).
     */
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('source_type', [
                'rest_api', 'soap', 'database', 'sftp', 'file_upload', 'webhook', 'odata', 'graphql', 'jdbc',
            ])->default('rest_api');
            $table->enum('system_category', [
                'core_banking', 'erp', 'hrms', 'itsm', 'identity', 'payments', 'tax',
                'crm', 'ticketing', 'log', 'spreadsheet', 'other',
            ])->default('other');
            $table->enum('vendor_key', [
                'finacle', 'flexcube', 't24', 'bankone', 'imal', 'bancs', 'nibss', 'interswitch',
                'remita', 'sap', 'dynamics365', 'sage', 'odoo', 'm365', 'entra', 'servicenow',
                'freshservice', 'firs_mbs', 'custom',
            ])->default('custom');
            $table->text('connection_config')->nullable();
            $table->enum('auth_type', ['none', 'basic', 'api_key', 'oauth2', 'certificate', 'jwt'])->default('none');
            $table->string('credentials_ref')->nullable();
            $table->text('credentials_encrypted')->nullable();
            $table->string('schedule_cron', 100)->nullable();
            $table->string('timezone', 64)->default('Africa/Lagos');
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_sync_at')->nullable();
            $table->enum('last_sync_status', ['Success', 'Partial', 'Failed', 'Never'])->default('Never');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->enum('health_status', ['Healthy', 'Degraded', 'Failed', 'Unknown'])->default('Unknown');

            // Circuit breaker + throttling. Thresholds are per-source data,
            // never constants in a service (R1).
            $table->unsignedInteger('failure_threshold')->default(5);
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->timestamp('paused_at')->nullable();
            $table->string('paused_reason')->nullable();

            $table->text('data_residency_note')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
            $table->index('tenant_id');
            $table->index('owner_id');
            $table->index('created_by');
            $table->index('approved_by');
            $table->index('is_active');
            $table->index('health_status');
            $table->index('last_sync_status');
            $table->index(['tenant_id', 'vendor_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
