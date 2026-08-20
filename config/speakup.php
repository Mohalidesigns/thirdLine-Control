<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IP intelligence driver
    |--------------------------------------------------------------------------
    |
    | How ASN / ISP / geo-coarse location are resolved at submission time.
    | 'none' records null with a source flag of "unresolved" — the platform
    | never fabricates a value it did not obtain (CR acceptance rule).
    | 'ip-api' calls the configured HTTP endpoint; deployments that map it
    | in config/residency.php endpoint_regions keep the residency guard
    | honest about where the lookup goes.
    |
    */

    'ip_intelligence' => [
        'driver' => env('SPEAKUP_IP_INTEL_DRIVER', 'none'),
        'endpoint' => env('SPEAKUP_IP_INTEL_ENDPOINT', 'http://ip-api.com/json'),
        'timeout_seconds' => env('SPEAKUP_IP_INTEL_TIMEOUT', 3),
    ],

    // Reverse DNS uses the system resolver and can block; a deployment
    // behind a slow resolver can switch it off without losing the rest.
    'reverse_dns' => env('SPEAKUP_REVERSE_DNS', true),

    /*
    |--------------------------------------------------------------------------
    | Datacentre / VPN / proxy detection
    |--------------------------------------------------------------------------
    |
    | Case-insensitive substrings matched against the resolved ISP name and
    | the reverse-DNS hostname. A hit raises the Tier 1 anomaly signal only
    | — a signal is decision support, never evidence of a false report.
    |
    */

    'datacentre_keywords' => [
        'amazon', 'aws', 'google cloud', 'gcp', 'azure', 'microsoft corporation',
        'digitalocean', 'linode', 'akamai', 'ovh', 'hetzner', 'vultr', 'contabo',
        'hosting', 'datacenter', 'data center', 'colocation', 'vpn', 'proxy',
        'm247', 'leaseweb', 'choopa', 'server',
    ],

    // A submission completed faster than this many seconds raises the
    // possible-bot / copy-paste anomaly signal.
    'fast_submission_seconds' => 20,

    // An approved Tier 2 reveal stays usable this long before it expires
    // and a fresh request-and-approval is needed.
    'reveal_validity_hours' => 72,

    /*
    |--------------------------------------------------------------------------
    | Tenant setting defaults (settings.speak_up.*)
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'metadata_capture' => true,
        'anonymous_mode' => true,
        'retention_months' => 24,
        'reason_codes' => [
            'suspected_false_report' => 'Suspected false report',
            'vexatious_reporting' => 'Vexatious or repeated reporting',
            'regulatory_request' => 'Regulatory or law-enforcement request',
            'safety_threat' => 'Threat to a person\'s safety',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Records of processing (NDPA)
    |--------------------------------------------------------------------------
    |
    | Registered purpose, lawful basis and retention for the reporter
    | metadata processing activity. Shown on the admin settings screen and
    | available to the tenant's records-of-processing export.
    |
    */

    'records_of_processing' => [
        'activity' => 'Speak Up reporter technical metadata',
        'purpose' => 'Fraud and false-report prevention; preserving the integrity of whistleblowing investigations.',
        'lawful_basis' => 'Legitimate interest (NDPA s.25(1)(f)) — preventing abuse of the reporting channel, '
            .'balanced by tiered access, second-person approval for identifying data, and disclosure at collection.',
        'retention' => 'Configurable per tenant; default 24 months from case closure, then hard-deleted.',
    ],

];
