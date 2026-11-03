<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Logger;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

final class MonologLogger implements LoggerInterface
{
    public function __construct(private readonly PsrLoggerInterface $logger)
    {
    }

    public function log(array $payload): void
    {
        $this->logger->error('Application error', $payload);
    }
}
