<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Logger;

use RuntimeException;

final class KibanaLogger implements LoggerInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeoutSeconds = 2,
        private readonly array $headers = [],
    ) {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The Kibana endpoint must be a valid HTTP or HTTPS URL.');
        }

        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('The Kibana timeout must be at least one second.');
        }
    }

    public function log(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $this->headers,
        );
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($this->endpoint, false, $context);
        $statusLine = $http_response_header[0] ?? '';

        if ($response === false || preg_match('/\s([45]\d{2})\s/', $statusLine) === 1) {
            throw new RuntimeException(sprintf(
                'Kibana logging failed: %s',
                $statusLine !== '' ? $statusLine : 'unable to reach the configured endpoint.',
            ));
        }
    }
}
