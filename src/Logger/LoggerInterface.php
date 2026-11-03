<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Logger;

interface LoggerInterface
{
    public function log(array $payload): void;
}
