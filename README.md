# Business Error Handler

A framework-agnostic PHP 8.1+ error handler that emits JSON-safe error responses, logs original exception details, supports correlation IDs, and can send structured logs to Kibana or any custom logger.

## Installation

```bash
composer require dwiky-danov/business-error-handler
```

Laravel's Artisan integration is auto-discovered when Laravel is installed. For other frameworks, only the core package and PSR-3 are required.

## Basic use

Register once during application bootstrap:

```php
use BusinessErrorHandler\ErrorHandler;

ErrorHandler::register([
    'environment' => getenv('APP_ENV') ?: 'production',
]);
```

The global exception and PHP error handlers emit a JSON response with `success`, `message`, and `correlation_id`. In production, unexpected exception messages and traces are never included in the response. In development, the response also includes exception class, message, file, line, and trace.

Business rule errors are safe to expose in every environment:

```php
use BusinessErrorHandler\BusinessValidationException;

throw new BusinessValidationException('Insufficient balance.');
```

### Safe custom messages

Configure exception-specific messages by class name:

```php
ErrorHandler::register([
    'environment' => 'production',
    RuntimeException::class => ['message' => 'System processing failed.'],
    PDOException::class => ['message' => 'Database operation failed.'],
]);
```

Custom messages are exposed in production; unconfigured exceptions use `Internal Server Error`. You can also use the `exception_messages` key to keep message configuration separate from handler options.

### Logging

Any `BusinessErrorHandler\Logger\LoggerInterface` implementation can be passed as `logger`. The included `MonologLogger` adapts a PSR-3 logger:

```php
use BusinessErrorHandler\ErrorHandler;
use BusinessErrorHandler\Logger\MonologLogger;

ErrorHandler::register(['logger' => new MonologLogger($monolog)]);
```

Enable the built-in Kibana HTTP logger with `kibana_logging => true`. Its endpoint defaults to `KIBANA_URL` or `http://localhost:9200/error-handler/_doc`; set `kibana_url`, `kibana_timeout`, or `kibana_headers` to customize it. Logged payloads contain `url`, `request_json`, `response_json`, `http_status`, `request_time` in milliseconds, `correlation_id`, and private exception details.

Frameworks that already parse request bodies can supply request context without reading `php://input`. For long-running workers, provide `request_time` in milliseconds to get a per-request duration:

```php
ErrorHandler::register([
    'logger' => $logger,
    'request_context' => static fn (): array => [
        'url' => '/api/v1/payment',
        'request_json' => request()->all(),
        'request_time' => $requestTimeInMilliseconds,
    ],
]);
```

The incoming `X-Correlation-ID` is reused when it contains only letters, numbers, `.`, `_`, or `-` (up to 128 characters); otherwise a UUID v4 is generated. It is included in both response and log, and sent as a response header.

### Laravel Artisan generator

```bash
php artisan make:errorhandler
php artisan make:errorhandler --logkibana
```

The command creates `config/error-handler.php`. Register the handler from the application's exception/bootstrap setup with:

```php
ErrorHandler::register(config('error-handler'));
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

The package requires PHP 8.1+ and `psr/log` 3.x. Laravel and Monolog are optional integrations.
