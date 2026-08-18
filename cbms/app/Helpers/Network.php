<?php

namespace App\Helpers;

class Network
{
    private static ?array $trustedProxies = null;

    public static function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!self::isFromTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            if (!empty($_SERVER[$header])) {
                return trim(explode(',', $_SERVER[$header])[0]);
            }
        }

        return $remoteAddr;
    }

    private static function isFromTrustedProxy(string $remoteAddr): bool
    {
        if (self::$trustedProxies === null) {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            self::$trustedProxies = $config['trusted_proxies'] ?? [];
        }

        if (empty(self::$trustedProxies)) {
            return false;
        }

        foreach (self::$trustedProxies as $proxy) {
            if ($proxy === $remoteAddr) {
                return true;
            }
            if (str_contains($proxy, '/') && self::ipInCidr($remoteAddr, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong    = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int)$bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
