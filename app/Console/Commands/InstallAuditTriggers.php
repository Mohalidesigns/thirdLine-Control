<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Installs the audit_trails immutability triggers (DEF-004). Idempotent —
 * safe to re-run. Exists as a command because CREATE TRIGGER needs the
 * SUPER privilege (or log_bin_trust_function_creators=1) on a MySQL host
 * with binary logging, which a hardened production DB user may not hold at
 * deploy time; the migration warns and defers to this command instead of
 * blocking the whole deploy.
 */
class InstallAuditTriggers extends Command
{
    protected $signature = 'audit:install-triggers';

    protected $description = 'Install the storage-layer immutability triggers on audit_trails (DEF-004)';

    public function handle(): int
    {
        try {
            self::install();
        } catch (\Throwable $e) {
            $this->error('Trigger installation failed: '.$e->getMessage());
            $this->line('');
            $this->line('On MySQL with binary logging, either grant the migration user the');
            $this->line('SUPER/SET_USER_ID privilege, or have a DBA run:');
            $this->line('  SET GLOBAL log_bin_trust_function_creators = 1;');
            $this->line('then re-run: php artisan audit:install-triggers');

            return self::FAILURE;
        }

        $this->info('audit_trails immutability triggers installed.');

        return self::SUCCESS;
    }

    /** Shared with the migration. Throws on failure. */
    public static function install(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => self::mysqlTriggers(),
            'sqlite' => self::sqliteTriggers(),
            default => null,
        };
    }

    public static function drop(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_trails_immutable_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_trails_immutable_delete');
    }

    private static function mysqlTriggers(): void
    {
        self::drop();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_trails_immutable_update
            BEFORE UPDATE ON audit_trails FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail records are immutable.'
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_trails_immutable_delete
            BEFORE DELETE ON audit_trails FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail records are immutable.'
        SQL);
    }

    private static function sqliteTriggers(): void
    {
        self::drop();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_trails_immutable_update
            BEFORE UPDATE ON audit_trails
            BEGIN
                SELECT RAISE(ABORT, 'Audit trail records are immutable.');
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_trails_immutable_delete
            BEFORE DELETE ON audit_trails
            BEGIN
                SELECT RAISE(ABORT, 'Audit trail records are immutable.');
            END
        SQL);
    }
}
