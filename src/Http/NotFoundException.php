<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Http;

class NotFoundException extends BadRequestException
{
    protected int $statusCode = 404;
}
