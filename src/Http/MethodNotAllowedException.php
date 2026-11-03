<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Http;

class MethodNotAllowedException extends BadRequestException
{
    protected int $statusCode = 405;
}
