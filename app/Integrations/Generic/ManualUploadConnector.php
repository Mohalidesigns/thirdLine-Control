<?php

namespace App\Integrations\Generic;

use App\Integrations\ConnectorException;
use App\Integrations\Contracts\Capability;
use App\Integrations\Contracts\Connector;
use App\Integrations\Contracts\DatasetDescriptor;
use App\Models\DataSourceDataset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Manual / spreadsheet upload (12.2, priority 1).
 *
 * Most African institutions have at least one control whose evidence is
 * a spreadsheet somebody emails monthly. Rather than pretend otherwise,
 * this connector makes that a first-class source: the officer uploads the
 * workbook, the same Phase 9 validation pipeline checks it against the
 * dataset's declared schema, and the rule engine then treats it exactly
 * like a Finacle extract.
 *
 * extraction_config:
 *   upload_path   ccm/uploads/2026-08/aml-alerts.xlsx   (set by the upload)
 *   disk          local
 *   sheet         0|"Alerts"
 *   header_row    1
 */
class ManualUploadConnector extends Connector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'custom',
            label: 'Manual upload (Excel / CSV)',
            transport: 'file_upload',
            authTypes: ['none'],
            supportsIncremental: false,
            supportsDiscovery: false,
            supportsPagination: false,
            verified: true,
            notCovered: [
                'Nothing is automated — an upload is only as timely as the person who does it.',
                'Formulas are read as their cached values, not recalculated.',
            ],
            documentation: 'Upload a workbook against the dataset; the declared schema is enforced on ingestion.',
        );
    }

    public function authenticate(): void
    {
        // Nothing to authenticate: the upload already passed through the
        // authenticated request that created it.
    }

    public function testConnection(): array
    {
        return ['ok' => true, 'message' => 'Manual source — nothing to reach.', 'latency_ms' => 0];
    }

    public function listDatasets(): array
    {
        return [new DatasetDescriptor(
            key: 'upload',
            name: 'Uploaded workbook',
            description: 'A worksheet uploaded by a control officer.',
            extractionConfig: ['sheet' => 0, 'header_row' => 1],
        )];
    }

    public function extract(DataSourceDataset $dataset, ?CarbonImmutable $since = null): iterable
    {
        $config = $dataset->extraction_config ?? [];
        $path = (string) ($config['upload_path'] ?? '');

        if ($path === '') {
            throw new ConnectorException('No workbook has been uploaded for this dataset yet.');
        }

        $disk = Storage::disk((string) ($config['disk'] ?? 'local'));

        if (! $disk->exists($path)) {
            throw new ConnectorException('The uploaded workbook is no longer on disk.');
        }

        $absolute = $disk->path($path);

        try {
            $reader = IOFactory::createReaderForFile($absolute);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolute);
        } catch (\Throwable $e) {
            throw ConnectorException::from($e, 'The workbook could not be read');
        }

        $sheet = is_int($config['sheet'] ?? 0)
            ? $spreadsheet->getSheet((int) ($config['sheet'] ?? 0))
            : $spreadsheet->getSheetByName((string) $config['sheet']);

        if ($sheet === null) {
            throw new ConnectorException('The named worksheet is not in the workbook.');
        }

        $headerRow = max(1, (int) ($config['header_row'] ?? 1));
        $rows = $sheet->toArray(null, true, true, false);
        $headers = array_map(
            fn ($h) => trim((string) $h),
            $rows[$headerRow - 1] ?? [],
        );

        foreach (array_slice($rows, $headerRow) as $row) {
            $record = [];

            foreach ($headers as $position => $header) {
                if ($header === '') {
                    continue;
                }

                $record[$header] = $row[$position] ?? null;
            }

            if (array_filter($record, fn ($v) => $v !== null && $v !== '') === []) {
                continue;
            }

            yield $record;
        }
    }
}
