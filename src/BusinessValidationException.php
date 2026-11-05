<?php

declare(strict_types=1);

namespace BusinessErrorHandler;

use RuntimeException;

final class BusinessValidationException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?array $data = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getData(): ?array
    {
        return $this->data;
    }
}
