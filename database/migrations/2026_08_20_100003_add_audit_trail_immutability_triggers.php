<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEF-004 — audit_trails immutability was enforced only in
 * AuditTrail::booted(), which any query-builder call, raw statement or
 * console command bypasses. These triggers put the guarantee in the
 * storage layer, where FR-12.5 ("tamper-evident, immutable") actually
 * needs it. Inserts remain unrestricted — the table is append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => $this->createMysqlTriggers(),
            'sqlite' => $this->createSqliteTriggers(),
            default => null,
        };
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_trails_immutable_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_trails_immutable_delete');
    }

    private function createMysqlTriggers(): void
    {
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

    private function createSqliteTriggers(): void
    {
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
};
