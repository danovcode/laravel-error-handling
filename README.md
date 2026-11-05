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

The global exception and PHP error handlers emit a JSON response with `status: false`, `message`, and `correlation_id`. In production, unexpected exception messages and traces are never included in the response. In development, the response also includes exception class, message, file, line, and trace.

Business rule errors are safe to expose in every environment:

```php
use BusinessErrorHandler\BusinessValidationException;

throw new BusinessValidationException('Insufficient balance.');
```

You can optionally attach response data of any JSON-encodable PHP type (array, object, string, number, boolean, or `null`) to a business validation exception. It is included as `data` in the error response:

```php
throw new BusinessValidationException(
    'Insufficient balance.',
    data: [
        'available_balance' => 50,
        'required_amount' => 75,
    ],
);
```

The response contains `status: false`, `message`, and the provided `data`. If no data argument is provided, the `data` key is omitted. Explicitly passing `data: null` includes `"data": null`. Values that PHP cannot encode as JSON (such as resources and closures) cannot be returned in a JSON response.

Laravel `ValidationException` responses expose the first validation message as `message`. When there are multiple validation messages, the response also includes the full field-keyed `validation` array:

```json
{
    "status": false,
    "message": "username is required",
    "validation": {
        "nik": ["username is required.", "username has to be string"],
        "reservation_id": ["transaction id is required."]
    },
    "correlation_id": "..."
}
```

### Safe custom messages

Configure exception-specific messages by class name:

```php
ErrorHandler::register([
    'environment' => 'production',
    RuntimeException::class => ['message' => 'System processing failed.'],
    PDOException::class => ['message' => 'Database operation failed.'],
    'exception_messages' => [
        Illuminate\Database\Eloquent\ModelNotFoundException::class => [
            'message' => 'hasil Dari [Model Name] tidak di temukan',
        ],
    ],
]);
```

Custom messages are exposed in production. `{model}` is replaced with the short model class name for Laravel's `ModelNotFoundException` (for example, `App\Models\Balance` becomes `Balance`). `{model_name}`, `:model`, and `[Model Name]` are also supported. Unrecognized exceptions use `Internal Server Error`; recognized exception types use safe built-in defaults. See [Default Exception Messages.xlsx](./Default%20Exception%20Messages%20Reference.xlsx) for the full default-message reference.

For Laravel 11+, Laravel renders request exceptions through its own exception pipeline, so register a renderer there to use the package's response for model-not-found errors:

```php
use BusinessErrorHandler\ErrorHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

->withExceptions(function (Exceptions $exceptions): void {
    ErrorHandler::register(config('error-handler'));

    $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
        return response()->json(ErrorHandler::render($exception), 404);
    });
})
```

In Laravel 10 and earlier, add the equivalent `renderable` callback to `app/Exceptions/Handler.php`. This ensures Laravel's request exception handler uses the package-formatted message rather than its default exception response.

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
