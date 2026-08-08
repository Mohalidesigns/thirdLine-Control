<?php

namespace App\Integrations;

use App\Integrations\Contracts\Capability;
use App\Integrations\Contracts\Connector;
use App\Integrations\CoreBanking\BankOneConnector;
use App\Integrations\CoreBanking\FinacleConnector;
use App\Integrations\CoreBanking\FlexcubeConnector;
use App\Integrations\CoreBanking\T24Connector;
use App\Integrations\Erp\Dynamics365Connector;
use App\Integrations\Erp\SageConnector;
use App\Integrations\Erp\SapConnector;
use App\Integrations\Generic\ManualUploadConnector;
use App\Integrations\Generic\RestConnector;
use App\Integrations\Generic\SftpCsvConnector;
use App\Integrations\Generic\SqlConnector;
use App\Integrations\Microsoft\GraphConnector;
use App\Integrations\Payments\NibssConnector;
use App\Integrations\Tax\FirsMbsConnector;
use App\Models\DataSource;

/**
 * Vendor key → connector class (12.1).
 *
 * A vendor with no dedicated class falls back to the generic connector
 * for its transport, so registering an unmapped system is configuration
 * rather than a code change (R1).
 */
final class ConnectorRegistry
{
    /** @var array<string, class-string<Connector>> */
    private const VENDORS = [
        'finacle' => FinacleConnector::class,
        'flexcube' => FlexcubeConnector::class,
        't24' => T24Connector::class,
        'bankone' => BankOneConnector::class,
        'nibss' => NibssConnector::class,
        'sap' => SapConnector::class,
        'dynamics365' => Dynamics365Connector::class,
        'sage' => SageConnector::class,
        'firs_mbs' => FirsMbsConnector::class,
        'm365' => GraphConnector::class,
        'entra' => GraphConnector::class,
    ];

    /** @var array<string, class-string<Connector>> */
    private const TRANSPORTS = [
        'rest_api' => RestConnector::class,
        'graphql' => RestConnector::class,
        'odata' => RestConnector::class,
        'webhook' => RestConnector::class,
        'soap' => RestConnector::class,
        'database' => SqlConnector::class,
        'jdbc' => SqlConnector::class,
        'sftp' => SftpCsvConnector::class,
        'file_upload' => ManualUploadConnector::class,
    ];

    public static function resolve(DataSource $source): Connector
    {
        $class = self::classFor($source->vendor_key, $source->source_type);

        return new $class($source);
    }

    /** @return class-string<Connector> */
    public static function classFor(string $vendorKey, string $sourceType): string
    {
        return self::VENDORS[$vendorKey]
            ?? self::TRANSPORTS[$sourceType]
            ?? RestConnector::class;
    }

    public static function capabilitiesFor(string $vendorKey, string $sourceType): Capability
    {
        return self::classFor($vendorKey, $sourceType)::capabilities();
    }

    /**
     * The full capability matrix, for the admin screen and the docs.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function matrix(): array
    {
        $classes = array_values(array_unique([
            ...array_values(self::VENDORS),
            ...array_values(self::TRANSPORTS),
        ]));

        $matrix = array_map(fn (string $class) => $class::capabilities()->toArray(), $classes);

        usort($matrix, fn ($a, $b) => [$b['verified'], $a['label']] <=> [$a['verified'], $b['label']]);

        return $matrix;
    }

    /** True when the connector has been exercised against a live system. */
    public static function isVerified(string $vendorKey, string $sourceType): bool
    {
        return self::capabilitiesFor($vendorKey, $sourceType)->verified;
    }
}
