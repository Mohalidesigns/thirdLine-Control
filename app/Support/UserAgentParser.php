<?php

namespace App\Support;

/**
 * Dependency-free user-agent parsing for the Speak Up metadata layer (CR).
 *
 * Deliberately coarse: browser family and version, OS family and version,
 * device class, and a device model only where the UA plainly exposes one.
 * Anything the string does not state is returned as null — a value the
 * platform did not obtain is never fabricated.
 */
class UserAgentParser
{
    /**
     * @return array{browser: ?string, browser_version: ?string, os: ?string,
     *               os_version: ?string, device_type: ?string, device_model: ?string}
     */
    public static function parse(?string $ua): array
    {
        $null = [
            'browser' => null, 'browser_version' => null,
            'os' => null, 'os_version' => null,
            'device_type' => null, 'device_model' => null,
        ];

        if ($ua === null || trim($ua) === '') {
            return $null;
        }

        return [
            ...$null,
            ...self::browser($ua),
            ...self::os($ua),
            'device_type' => self::deviceType($ua),
            'device_model' => self::deviceModel($ua),
        ];
    }

    /** @return array{browser: ?string, browser_version: ?string} */
    private static function browser(string $ua): array
    {
        // Order matters: Edge and Opera carry Chrome/Safari tokens, and
        // Chrome carries Safari's, so the most specific families go first.
        $families = [
            'Edge' => '/Edg(?:e|A|iOS)?\/([\d.]+)/',
            'Opera' => '/OPR\/([\d.]+)|Opera[\/ ]([\d.]+)/',
            'Samsung Internet' => '/SamsungBrowser\/([\d.]+)/',
            'Firefox' => '/(?:Firefox|FxiOS)\/([\d.]+)/',
            'Chrome' => '/(?:Chrome|CriOS)\/([\d.]+)/',
            'Safari' => '/Version\/([\d.]+).*Safari/',
        ];

        foreach ($families as $name => $pattern) {
            if (preg_match($pattern, $ua, $m)) {
                $version = collect(array_slice($m, 1))->first(fn ($v) => $v !== '' && $v !== null);

                return ['browser' => $name, 'browser_version' => $version];
            }
        }

        return ['browser' => null, 'browser_version' => null];
    }

    /** @return array{os: ?string, os_version: ?string} */
    private static function os(string $ua): array
    {
        if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
            $names = [
                '10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7',
            ];

            return ['os' => 'Windows', 'os_version' => $names[$m[1]] ?? $m[1]];
        }

        if (preg_match('/iPhone OS ([\d_]+)|CPU OS ([\d_]+)/', $ua, $m)) {
            $version = str_replace('_', '.', $m[1] !== '' ? $m[1] : ($m[2] ?? ''));

            return ['os' => 'iOS', 'os_version' => $version ?: null];
        }

        if (preg_match('/Android ([\d.]+)/', $ua, $m)) {
            return ['os' => 'Android', 'os_version' => $m[1]];
        }

        if (preg_match('/Mac OS X ([\d_.]+)/', $ua, $m)) {
            return ['os' => 'macOS', 'os_version' => str_replace('_', '.', $m[1])];
        }

        if (stripos($ua, 'CrOS') !== false) {
            return ['os' => 'ChromeOS', 'os_version' => null];
        }

        if (stripos($ua, 'Linux') !== false) {
            return ['os' => 'Linux', 'os_version' => null];
        }

        return ['os' => null, 'os_version' => null];
    }

    private static function deviceType(string $ua): ?string
    {
        if (preg_match('/iPad|Tablet|Kindle|Silk/i', $ua)) {
            return 'tablet';
        }

        // Android without "Mobile" is a tablet by Google's own convention.
        if (stripos($ua, 'Android') !== false) {
            return stripos($ua, 'Mobile') !== false ? 'mobile' : 'tablet';
        }

        if (preg_match('/iPhone|Mobile|Windows Phone/i', $ua)) {
            return 'mobile';
        }

        if (preg_match('/Windows NT|Macintosh|CrOS|X11/i', $ua)) {
            return 'desktop';
        }

        return null;
    }

    private static function deviceModel(string $ua): ?string
    {
        if (stripos($ua, 'iPhone') !== false) {
            return 'iPhone';
        }

        if (stripos($ua, 'iPad') !== false) {
            return 'iPad';
        }

        // Android UAs expose "; <model> Build/" — the one place a model
        // name is stated rather than inferred.
        if (preg_match('/;\s*([^;)]+?)\s+Build\//', $ua, $m)) {
            return substr(trim($m[1]), 0, 120) ?: null;
        }

        return null;
    }
}
