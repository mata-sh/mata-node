<?php

declare(strict_types=1);

namespace Mata\Security;

final class IpAllowlist
{
    /**
     * @param array<int,string> $allowedEntries
     */
    public function isAllowed(string $clientIp, array $allowedEntries): bool
    {
        $clientIp = trim($clientIp);
        if (!self::isValidIp($clientIp)) {
            return false;
        }

        foreach ($allowedEntries as $entry) {
            if ($this->matches($clientIp, trim($entry))) {
                return true;
            }
        }

        return false;
    }

    public static function isValidEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ('' === $entry) {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return self::isValidIp($entry);
        }

        $parts = explode('/', $entry);
        if (2 !== count($parts)) {
            return false;
        }

        [$ip, $prefix] = $parts;
        $ip = trim($ip);
        $prefix = trim($prefix);

        if (!self::isValidIp($ip) || '' === $prefix || !ctype_digit($prefix)) {
            return false;
        }

        $packedIp = inet_pton($ip);
        if (false === $packedIp) {
            return false;
        }

        $maxPrefix = 4 === strlen($packedIp) ? 32 : 128;
        $prefixLength = (int) $prefix;

        return $prefixLength >= 0 && $prefixLength <= $maxPrefix;
    }

    public static function isValidIp(string $ip): bool
    {
        return false !== filter_var($ip, FILTER_VALIDATE_IP);
    }

    private function matches(string $clientIp, string $entry): bool
    {
        if (!self::isValidEntry($entry)) {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return inet_pton($clientIp) === inet_pton($entry);
        }

        [$networkIp, $prefix] = explode('/', $entry, 2);
        $clientIpBinary = inet_pton($clientIp);
        $networkIpBinary = inet_pton(trim($networkIp));

        if (false === $clientIpBinary || false === $networkIpBinary) {
            return false;
        }

        if (strlen($clientIpBinary) !== strlen($networkIpBinary)) {
            return false;
        }

        return $this->binaryMatchesCidr(
            $clientIpBinary,
            $networkIpBinary,
            (int) trim($prefix)
        );
    }

    private function binaryMatchesCidr(string $clientIp, string $networkIp, int $prefixLength): bool
    {
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($clientIp, 0, $fullBytes) !== substr($networkIp, 0, $fullBytes)) {
            return false;
        }

        if (0 === $remainingBits) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($clientIp[$fullBytes]) & $mask) === (ord($networkIp[$fullBytes]) & $mask);
    }
}
