<?php

use App\Console\Commands\InstallAuditTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * DEF-004 — audit_trails immutability was enforced only in
 * AuditTrail::booted(), which any query-builder call, raw statement or
 * console command bypasses. These triggers put the guarantee in the
 * storage layer, where FR-12.5 ("tamper-evident, immutable") actually
 * needs it. Inserts remain unrestricted — the table is append-only.
 *
 * CREATE TRIGGER on a binlog-enabled MySQL needs SUPER (or
 * log_bin_trust_function_creators=1). A hardened deploy user may lack it
 * (error 1419) — that must not block the whole deploy, so the failure is
 * downgraded to a LOUD warning and the operator installs the triggers via
 * `php artisan audit:install-triggers` once the server flag is set. The
 * model-layer guard still holds in the meantime.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            InstallAuditTriggers::install();
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), '1419')) {
                throw $e;
            }

            $message = 'audit_trails immutability triggers NOT installed — the database user lacks the '
                .'privilege to create triggers while binary logging is on (error 1419). The audit trail is '
                .'currently protected at the model layer only. Have a DBA run '
                .'"SET GLOBAL log_bin_trust_function_creators = 1;" then run '
                .'"php artisan audit:install-triggers".';

            Log::warning($message);
            fwrite(STDERR, "\nWARNING: {$message}\n\n");
        }
    }

    public function down(): void
    {
        InstallAuditTriggers::drop();
    }
};
