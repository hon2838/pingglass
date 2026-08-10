<?php

namespace App\Services\Monitoring;

use Illuminate\Validation\ValidationException;

class SsrfProtection
{
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

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || empty($records)) {
            return null;
        }

        // Return the first safe IP
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip && self::isIpSafe($ip)) {
                return $ip;
            }
        }

        return null;
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
