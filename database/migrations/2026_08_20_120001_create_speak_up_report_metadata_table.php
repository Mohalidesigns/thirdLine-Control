<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR — Speak Up reporter technical metadata. One row per confidential
     * submission, none for the anonymous route: an anonymous case stores
     * nothing that identifies the reporter, and this table is the reason
     * the confidential route is labelled "confidential" rather than
     * "anonymous".
     *
     * Identifying columns (ip_address, ip_forwarded_chain, hostname,
     * reporter_user_id) are Laravel encrypted casts, so they are TEXT here
     * regardless of logical type and unreadable in a database dump. The
     * fingerprint hash and the geo/device columns stay cleartext so
     * correlation and Tier 1 signals work without decryption.
     */
    public function up(): void
    {
        Schema::create('speak_up_report_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->unique()->constrained('cases')->cascadeOnDelete();

            // Tier 2 — encrypted at rest.
            $table->text('ip_address')->nullable();
            $table->text('ip_forwarded_chain')->nullable();
            $table->text('hostname')->nullable();
            $table->text('reporter_user_id')->nullable();
            $table->string('hostname_source', 30)->default('unavailable');

            // Network intelligence snapshot, resolved at submission time.
            $table->string('asn', 40)->nullable();
            $table->string('isp')->nullable();
            $table->string('geo_country', 80)->nullable();
            $table->string('geo_region', 120)->nullable();
            $table->string('geo_city', 120)->nullable();

            // Device snapshot.
            $table->string('user_agent_raw', 500)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('browser_version', 40)->nullable();
            $table->string('os', 60)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('screen_resolution', 30)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->string('locale', 120)->nullable();

            // The correlation key: SHA-256 of a normalised, ordered
            // attribute string — never the raw attributes.
            $table->string('fingerprint_hash', 64)->nullable();

            // Session context.
            $table->unsignedInteger('session_duration_seconds')->nullable();
            $table->string('referrer', 500)->nullable();
            $table->boolean('is_authenticated')->default(false);

            // Where each captured group came from — 'server', 'client',
            // 'unresolved' or 'unavailable'. A missing value is recorded as
            // null with its source flag, never fabricated.
            $table->json('capture_sources')->nullable();

            // NDPA notice acknowledgement, stored with the capture it
            // authorises.
            $table->unsignedInteger('notice_version')->nullable();
            $table->timestamp('notice_acknowledged_at')->nullable();

            // useCurrent() because MySQL under NO_ZERO_DATE refuses a
            // required timestamp with no default; the service always
            // writes the value explicitly.
            $table->timestamp('captured_at')->useCurrent();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fingerprint_hash']);
            $table->index(['tenant_id', 'purge_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speak_up_report_metadata');
    }
};
