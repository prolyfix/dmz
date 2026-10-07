<?php

declare(strict_types=1);

namespace App\Service;

class BookingTargetUrlValidator
{
    public function isAllowed(string $url): bool
    {
        if (strlen($url) > 255 || false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (
            false === $parts
            || !isset($parts['scheme'], $parts['host'])
            || 'https' !== strtolower($parts['scheme'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && 443 !== $parts['port'])
        ) {
            return false;
        }

        $host = trim($parts['host'], '[]');
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);
        if (false === $records || [] === $records) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (null === $ip || !$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        return false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE);
    }
}
