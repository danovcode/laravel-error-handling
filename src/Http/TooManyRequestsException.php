<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Http;

class TooManyRequestsException extends BadRequestException
{
    protected int $statusCode = 429;
}
