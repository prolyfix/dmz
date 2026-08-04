<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SynstituteInstance;

class BookingForwarder
{
    public function forward(SynstituteInstance $instance, array $payload): array
    {
        $url = $instance->getBookingTargetUrl();
        if (!$this->isAllowedTargetUrl($url)) {
            return [
                'success' => false,
                'status' => 0,
                'body' => 'Target URL is not allowed',
            ];
        }

        $headers = [
            'Content-Type: application/json',
            'X-Dmz-Instance: ' . $instance->getIdentifier(),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $status = $this->extractStatusCode($http_response_header ?? []);

        return [
            'success' => false !== $responseBody && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => false === $responseBody ? '' : $responseBody,
        ];
    }

    private function isAllowedTargetUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ('https' !== strtolower((string) $parts['scheme'])) {
            return false;
        }

        $records = dns_get_record((string) $parts['host'], DNS_A + DNS_AAAA);
        if ([] === $records || false === $records) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (null === $ip) {
                continue;
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
