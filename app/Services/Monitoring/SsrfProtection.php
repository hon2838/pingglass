<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SsrfProtection
{
    private const DNS_CACHE_VERSION = 2;

    /**
     * Validate that a host is safe to probe (for use during target creation/editing).
     * Throws ValidationException on failure.
     */
    public static function validateHost(string $host): void
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $host)) {
                throw ValidationException::withMessages([
                    'host' => 'Invalid hostname format.',
                ]);
            }

            if (config('pingglass.allow_private_targets', false)) {
                return;
            }

            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if ($records === false || empty($records)) {
                return;
            }

            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if ($ip) {
                    self::validateIp($ip, $host);
                }
            }

            return;
        }

        self::validateIp($host, $host);
    }

    /**
     * Resolve and validate a host for probing.
     * Returns the resolved IP address, or null if resolution/validation fails.
     * Call this before each probe to prevent TOCTOU issues.
     */
    public static function resolveForProbe(string $host): ?string
    {
        // If it's already an IP, validate and return
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isIpSafe($host)) {
                return null;
            }
            return $host;
        }

        // It's a hostname — resolve it
        if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $host)) {
            return null;
        }

        $cacheKey = 'pingglass:dns:' . sha1(strtolower($host));
        try {
            $cached = Cache::get($cacheKey, '__pingglass_cache_miss__');
            if (is_array($cached) && array_key_exists('ip', $cached)) {
                $cachedIp = $cached['ip'];
                $safeIp = is_string($cachedIp) && self::isIpSafe($cachedIp)
                    ? $cachedIp
                    : null;

                // Entries written by the old five-minute cache did not have a
                // version. Upgrade them on access so a rolling deployment does
                // not trigger another synchronized cold-DNS cycle.
                if (($cached['version'] ?? null) !== self::DNS_CACHE_VERSION) {
                    self::cacheResolution($cacheKey, $safeIp);
                }

                return $safeIp;
            }
        } catch (\Throwable) {
            // Continue without caching if Redis/cache is unavailable.
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || empty($records)) {
            self::cacheResolution($cacheKey, null);
            return null;
        }

        // Return the first safe IP
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip && self::isIpSafe($ip)) {
                self::cacheResolution($cacheKey, $ip);
                return $ip;
            }
        }

        self::cacheResolution($cacheKey, null);
        return null;
    }

    private static function cacheResolution(string $key, ?string $ip): void
    {
        $failed = $ip === null;
        $ttlKey = $failed ? 'failure_cache_ttl' : 'success_cache_ttl';
        $jitterKey = $failed ? 'failure_cache_jitter' : 'success_cache_jitter';
        $baseTtl = max(1, (int) config("pingglass.dns.{$ttlKey}", $failed ? 300 : 3600));
        $jitter = max(0, (int) config("pingglass.dns.{$jitterKey}", $failed ? 300 : 3600));
        $jitterSeconds = $jitter === 0
            ? 0
            : hexdec(substr(sha1($key), 0, 8)) % ($jitter + 1);

        try {
            Cache::put($key, [
                'version' => self::DNS_CACHE_VERSION,
                'ip' => $ip,
            ], $baseTtl + $jitterSeconds);
        } catch (\Throwable) {
            // Probing must not fail just because DNS caching is unavailable.
        }
    }

    /**
     * Check if an IP address is safe to probe (no exception thrown).
     */
    private static function isIpSafe(string $ip): bool
    {
        if (config('pingglass.allow_private_targets', false)) {
            return true;
        }

        // Reject loopback and reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // IPv6 checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (str_starts_with(strtolower($ip), 'fe80:')) return false;
            if (str_starts_with(strtolower($ip), 'ff')) return false;
        }

        // Reject unspecified
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    private static function validateIp(string $ip, string $originalHost): void
    {
        if (config('pingglass.allow_private_targets', false)) {
            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw ValidationException::withMessages([
                'host' => "Target '{$originalHost}' resolves to a private/internal address. Private network monitoring is disabled by default.",
            ]);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (str_starts_with(strtolower($ip), 'fe80:')) {
                throw ValidationException::withMessages([
                    'host' => 'Link-local addresses are not allowed.',
                ]);
            }
            if (str_starts_with(strtolower($ip), 'ff')) {
                throw ValidationException::withMessages([
                    'host' => 'Multicast addresses are not allowed.',
                ]);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            throw ValidationException::withMessages([
                'host' => 'Unspecified addresses are not allowed.',
            ]);
        }
    }
}
