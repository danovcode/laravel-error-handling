<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Http;

use RuntimeException;

class BadRequestException extends RuntimeException
{
    protected int $statusCode = 400;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
