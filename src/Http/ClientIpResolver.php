<?php

declare(strict_types=1);

namespace Mata\Http;

use Mata\Security\IpAllowlist;

final class ClientIpResolver
{
    public function __construct(
        private readonly IpAllowlist $ipAllowlist = new IpAllowlist()
    ) {}

    /**
     * Resolve client IP from server variables.
     *
     * REMOTE_ADDR is authoritative by default. X-Forwarded-For is trusted only
     * when REMOTE_ADDR matches TRUSTED_PROXIES.
     *
     * @param array<string,mixed> $server
     * @param array<int,string>   $trustedProxies
     */
    public function resolve(array $server, array $trustedProxies): ClientIpResolution
    {
        $remoteAddr = $this->serverValue($server, 'REMOTE_ADDR');
        if (!IpAllowlist::isValidIp($remoteAddr)) {
            $remoteAddr = 'unknown';
        }

        $forwardedFor = $this->serverValue($server, 'HTTP_X_FORWARDED_FOR');

        if (!$this->canTrustForwardedFor($remoteAddr, $trustedProxies)) {
            return new ClientIpResolution(
                clientIp: $remoteAddr,
                remoteAddr: $remoteAddr,
                forwardedFor: '' === $forwardedFor ? null : $forwardedFor,
                usedTrustedProxy: false,
            );
        }

        $forwardedIp = $this->forwardedClientIp($forwardedFor, $trustedProxies);
        if (null === $forwardedIp) {
            return new ClientIpResolution(
                clientIp: $remoteAddr,
                remoteAddr: $remoteAddr,
                forwardedFor: '' === $forwardedFor ? null : $forwardedFor,
                usedTrustedProxy: false,
            );
        }

        return new ClientIpResolution(
            clientIp: $forwardedIp,
            remoteAddr: $remoteAddr,
            forwardedFor: $forwardedFor,
            usedTrustedProxy: true,
        );
    }

    /**
     * @param array<string,mixed> $server
     */
    private function serverValue(array $server, string $key): string
    {
        $value = $server[$key] ?? '';
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param array<int,string> $trustedProxies
     */
    private function canTrustForwardedFor(string $remoteAddr, array $trustedProxies): bool
    {
        if ('' === $remoteAddr || [] === $trustedProxies) {
            return false;
        }

        return $this->ipAllowlist->isAllowed($remoteAddr, $trustedProxies);
    }

    /**
     * @param array<int,string> $trustedProxies
     */
    private function forwardedClientIp(string $forwardedFor, array $trustedProxies): ?string
    {
        if ('' === $forwardedFor) {
            return null;
        }

        $validIps = [];
        foreach (explode(',', $forwardedFor) as $entry) {
            $ip = trim($entry);
            if (IpAllowlist::isValidIp($ip)) {
                $validIps[] = $ip;
            }
        }

        for ($i = count($validIps) - 1; $i >= 0; --$i) {
            if (!$this->ipAllowlist->isAllowed($validIps[$i], $trustedProxies)) {
                return $validIps[$i];
            }
        }

        return $validIps[0] ?? null;
    }
}
