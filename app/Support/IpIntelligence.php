<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reverse DNS, ASN/ISP and geo-coarse resolution for Speak Up metadata
 * capture (CR). Everything here is a best-effort snapshot taken at
 * submission time: an unresolved value is returned as null and the caller
 * records the 'unresolved' source flag — the platform never guesses.
 *
 * The default driver is 'none' so an installation resolves nothing until
 * its administrator points the lookup somewhere deliberately; a mapped
 * endpoint also puts the call under the residency guard's eye
 * (config/residency.php endpoint_regions).
 */
class IpIntelligence
{
    /**
     * @return array{hostname: ?string, asn: ?string, isp: ?string,
     *               geo_country: ?string, geo_region: ?string, geo_city: ?string,
     *               resolved: bool}
     */
    public static function resolve(?string $ip): array
    {
        $result = [
            'hostname' => null, 'asn' => null, 'isp' => null,
            'geo_country' => null, 'geo_country_code' => null,
            'geo_region' => null, 'geo_city' => null,
            'resolved' => false,
        ];

        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $result;
        }

        if (config('speakup.reverse_dns') && ! self::isPrivate($ip)) {
            $host = @gethostbyaddr($ip);
            $result['hostname'] = ($host !== false && $host !== $ip) ? substr($host, 0, 255) : null;
        }

        if (config('speakup.ip_intelligence.driver') === 'ip-api' && ! self::isPrivate($ip)) {
            $result = [...$result, ...self::fromIpApi($ip)];
        }

        return $result;
    }

    /**
     * Does this connection look like a datacentre, VPN or proxy exit? A
     * Tier 1 anomaly signal, computed from cleartext-safe inputs.
     */
    public static function looksLikeDatacentre(?string $isp, ?string $hostname): bool
    {
        $haystack = strtolower(trim(($isp ?? '').' '.($hostname ?? '')));

        if ($haystack === '') {
            return false;
        }

        foreach (config('speakup.datacentre_keywords', []) as $keyword) {
            if (str_contains($haystack, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    private static function fromIpApi(string $ip): array
    {
        try {
            $response = Http::timeout((int) config('speakup.ip_intelligence.timeout_seconds', 3))
                ->get(rtrim(config('speakup.ip_intelligence.endpoint'), '/').'/'.$ip, [
                    'fields' => 'status,country,countryCode,regionName,city,isp,as',
                ]);

            if (! $response->ok() || $response->json('status') !== 'success') {
                return ['resolved' => false];
            }

            return [
                'asn' => substr((string) $response->json('as'), 0, 40) ?: null,
                'isp' => substr((string) $response->json('isp'), 0, 255) ?: null,
                'geo_country' => $response->json('country'),
                'geo_country_code' => $response->json('countryCode'),
                'geo_region' => $response->json('regionName'),
                'geo_city' => $response->json('city'),
                'resolved' => true,
            ];
        } catch (\Throwable $e) {
            // A lookup outage must never lose a disclosure (R3 shape) —
            // the submission proceeds with unresolved network fields.
            Log::warning('Speak Up IP intelligence lookup failed', ['error' => $e->getMessage()]);

            return ['resolved' => false];
        }
    }

    private static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
