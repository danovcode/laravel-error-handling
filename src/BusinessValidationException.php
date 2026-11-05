<?php

declare(strict_types=1);

namespace BusinessErrorHandler;

use RuntimeException;

final class BusinessValidationException extends RuntimeException
{
    private readonly mixed $data;
    private readonly bool $hasData;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        mixed $data = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
        $this->hasData = func_num_args() >= 4;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function hasData(): bool
    {
        return $this->hasData;
    }
}
