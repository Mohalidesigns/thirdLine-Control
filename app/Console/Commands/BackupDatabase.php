<?php

namespace App\Console\Commands;

use App\Services\ResidencyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Residency-guarded database backup (16.2 backup layer, 16.3 reliability).
 * The dump is written through the Storage layer so the guard's disk map
 * is the single source of truth: a backup disk declared outside the
 * residency country refuses to accept a byte.
 */
class BackupDatabase extends Command
{
    protected $signature = 'atheris:backup
        {--disk= : Override the backup disk (defaults to residency.backup_disk)}
        {--keep=14 : Backups to retain, oldest pruned first}';

    protected $description = 'Dump the database to the residency-guarded backup disk';

    public function handle(ResidencyGuard $guard): int
    {
        $disk = $this->option('disk') ?: config('residency.backup_disk');

        $guard->assertBackupAllowed($disk);

        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $name = 'backups/atheris-'.now()->format('Ymd-His').'.sql';

        $dump = match ($config['driver']) {
            'mysql', 'mariadb' => $this->mysqlDump($config),
            'sqlite' => $this->sqliteDump($config),
            default => null,
        };

        if ($dump === null) {
            $this->error("Unsupported database driver '{$config['driver']}'.");

            return self::FAILURE;
        }

        Storage::disk($disk)->put($name, $dump);

        $this->prune($disk, (int) $this->option('keep'));

        $this->info(sprintf('Backup written: %s (%s, disk %s)', $name, strlen($dump).' bytes', $disk));

        return self::SUCCESS;
    }

    private function mysqlDump(array $config): ?string
    {
        $process = new Process([
            'mysqldump',
            '--host='.$config['host'],
            '--port='.($config['port'] ?? 3306),
            '--user='.$config['username'],
            '--single-transaction',
            '--routines',
            $config['database'],
        ], env: ['MYSQL_PWD' => $config['password'] ?? '']);

        $process->setTimeout(600)->run();

        if (! $process->isSuccessful()) {
            $this->error('mysqldump failed: '.$process->getErrorOutput());

            return null;
        }

        return $process->getOutput();
    }

    private function sqliteDump(array $config): ?string
    {
        // Logical dump via the connection itself — works for file databases
        // and the in-memory database the restore drill runs against.
        $pdo = DB::getPdo();
        $out = '-- Atheris logical backup '.now()->toIso8601String()."\n";

        $tables = DB::select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'");

        foreach ($tables as $table) {
            $out .= $table->sql.";\n";

            foreach (DB::table($table->name)->cursor() as $row) {
                $values = implode(', ', array_map(
                    fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value),
                    (array) $row,
                ));

                $out .= "INSERT INTO \"{$table->name}\" VALUES ({$values});\n";
            }
        }

        return $out;
    }

    private function prune(string $disk, int $keep): void
    {
        $backups = collect(Storage::disk($disk)->files('backups'))->sort()->values();

        if ($backups->count() > $keep) {
            $backups->take($backups->count() - $keep)
                ->each(fn ($file) => Storage::disk($disk)->delete($file));
        }
    }
}
