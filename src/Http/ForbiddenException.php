<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Http;

class ForbiddenException extends BadRequestException
{
    protected int $statusCode = 403;
}
