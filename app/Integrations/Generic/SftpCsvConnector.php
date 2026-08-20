<?php

namespace App\Integrations\Generic;

use App\Integrations\ConnectorException;
use App\Integrations\Contracts\Capability;
use App\Integrations\Contracts\Connector;
use App\Integrations\Contracts\DatasetDescriptor;
use App\Models\DataSourceDataset;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * SFTP (or any configured Laravel disk) delimited / fixed-width file
 * connector (12.2, priority 1).
 *
 * Nigerian core-banking extracts arrive as a nightly file more often than
 * as an API, so this is the workhorse. The dataset declares the layout:
 * delimiter and header row for CSV, or a field/width map for fixed width.
 *
 * connection_config:
 *   disk        sftp_core            (a disk configured in config/filesystems.php)
 *   root        /outbound/controls
 *
 * extraction_config:
 *   pattern     GL_POSTINGS_*.csv    (glob; the newest match is read)
 *   format      csv|fixed
 *   delimiter   ","
 *   has_header  true
 *   columns     ["id","amount"]      (csv without a header, or fixed-width order)
 *   widths      { "id": 12, "amount": 18 }   (fixed only)
 *   skip_lines  0
 *   trailer_lines 1
 */
class SftpCsvConnector extends Connector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'custom',
            label: 'SFTP / file drop (CSV or fixed-width)',
            transport: 'sftp',
            authTypes: ['basic', 'certificate'],
            supportsIncremental: false,
            supportsDiscovery: true,
            supportsPagination: false,
            verified: true,
            notCovered: [
                'No incremental extraction — a file drop is a full period file by nature.',
                'Encrypted or password-protected archives must be decrypted upstream.',
            ],
            documentation: 'Point at a disk and a filename pattern; the newest matching file is read each run.',
        );
    }

    public function authenticate(): void
    {
        // Credentials belong to the configured disk, so there is nothing to
        // acquire here. Presence of the disk is the check that matters.
        $disk = (string) $this->config('disk', 'local');

        if (! config("filesystems.disks.{$disk}")) {
            throw new ConnectorException("The disk '{$disk}' is not configured on this installation.");
        }
    }

    public function testConnection(): array
    {
        $started = microtime(true);

        try {
            $this->authenticate();
            $files = $this->disk()->files($this->root());

            return [
                'ok' => true,
                'message' => count($files).' file(s) visible in '.$this->root().'.',
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => ConnectorException::redactMessage($e->getMessage()),
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        }
    }

    public function listDatasets(): array
    {
        $this->authenticate();

        return array_map(
            fn (string $path) => new DatasetDescriptor(
                key: basename($path),
                name: basename($path),
                description: 'Discovered on '.$this->config('disk', 'local').'.',
                extractionConfig: ['pattern' => basename($path), 'format' => 'csv', 'has_header' => true],
            ),
            array_slice($this->disk()->files($this->root()), 0, 50),
        );
    }

    public function extract(DataSourceDataset $dataset, ?CarbonImmutable $since = null): iterable
    {
        $this->authenticate();

        $config = $dataset->extraction_config ?? [];
        $path = $this->newestMatch((string) ($config['pattern'] ?? '*'));

        if ($path === null) {
            throw new ConnectorException('No file on the source matches '.($config['pattern'] ?? '*').'.');
        }

        $contents = $this->disk()->get($path);

        if ($contents === null) {
            throw new ConnectorException("The file {$path} could not be read.");
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        $skip = (int) ($config['skip_lines'] ?? 0);
        $trailer = (int) ($config['trailer_lines'] ?? 0);

        if ($trailer > 0) {
            $lines = array_slice($lines, 0, max(0, count($lines) - $trailer));
        }

        $lines = array_slice($lines, $skip);

        yield from ($config['format'] ?? 'csv') === 'fixed'
            ? $this->parseFixed($lines, $config)
            : $this->parseCsv($lines, $config);
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $config
     * @return iterable<int, array<string, mixed>>
     */
    private function parseCsv(array $lines, array $config): iterable
    {
        $delimiter = (string) ($config['delimiter'] ?? ',');
        $headers = (array) ($config['columns'] ?? []);

        if (($config['has_header'] ?? true) && $lines !== []) {
            $headers = str_getcsv(array_shift($lines), $delimiter, '"', '\\');
        }

        $headers = array_values(array_map(fn ($h) => trim((string) $h), $headers));

        foreach ($lines as $line) {
            $values = str_getcsv($line, $delimiter, '"', '\\');

            if ($headers === []) {
                yield $values;

                continue;
            }

            // Ragged rows are the norm in a bank extract: pad short rows and
            // keep the overflow under numeric keys rather than dropping it.
            $row = [];

            foreach ($headers as $position => $header) {
                $row[$header] = $values[$position] ?? null;
            }

            for ($position = count($headers); $position < count($values); $position++) {
                $row['column_'.($position + 1)] = $values[$position];
            }

            yield $row;
        }
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $config
     * @return iterable<int, array<string, mixed>>
     */
    private function parseFixed(array $lines, array $config): iterable
    {
        $widths = (array) ($config['widths'] ?? []);

        if ($widths === []) {
            throw new ConnectorException('A fixed-width dataset must declare its field widths.');
        }

        foreach ($lines as $line) {
            $row = [];
            $offset = 0;

            foreach ($widths as $field => $width) {
                $row[$field] = trim(mb_substr($line, $offset, (int) $width));
                $offset += (int) $width;
            }

            yield $row;
        }
    }

    private function newestMatch(string $pattern): ?string
    {
        $files = $this->disk()->files($this->root());
        $matched = array_values(array_filter($files, fn ($f) => fnmatch($pattern, basename($f))));

        if ($matched === []) {
            return null;
        }

        usort($matched, fn ($a, $b) => $this->disk()->lastModified($b) <=> $this->disk()->lastModified($a));

        return $matched[0];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) $this->config('disk', 'local'));
    }

    private function root(): string
    {
        return trim((string) $this->config('root', ''), '/');
    }
}
