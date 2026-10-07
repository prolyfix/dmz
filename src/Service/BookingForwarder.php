<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SynstituteInstance;

class BookingForwarder
{
    public function __construct(
        private readonly BookingTargetUrlValidator $targetUrlValidator,
    ) {
    }

    public function forward(SynstituteInstance $instance, array $payload): array
    {
        $url = $instance->getBookingTargetUrl();
        if (!$this->targetUrlValidator->isAllowed($url)) {
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
                'follow_location' => 0,
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
