<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Http;

class UnauthorizedException extends BadRequestException
{
    protected int $statusCode = 401;
}
