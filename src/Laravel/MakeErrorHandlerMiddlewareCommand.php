<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Laravel;

use Illuminate\Console\Command;
use RuntimeException;

final class MakeErrorHandlerMiddlewareCommand extends Command
{
    protected $signature = 'make:errorhandler-middleware';

    protected $description = 'Generate middleware that logs completed request and response data';

    public function handle(): int
    {
        $path = base_path('app/Http/Middleware/LogRequestResponse.php');
        if (is_file($path)) {
            $this->error('The error handler middleware already exists: ' . $path);

            return self::FAILURE;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the middleware directory: ' . $directory);
        }

        $contents = <<<'PHP'
<?php

namespace App\Http\Middleware;

use BusinessErrorHandler\Logger\KibanaLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogRequestResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);
        $body = $response->getContent();
        $responseJson = null;

        if (is_string($body)) {
            $decodedBody = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $responseJson = $decodedBody;

                if (
                    config('app.env') !== 'production'
                    && !config('app.debug')
                    && is_array($responseJson)
                    && array_key_exists('exception', $responseJson)
                ) {
                    unset($responseJson['exception']);
                    $response->setContent(json_encode(
                        $responseJson,
                        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                    ));
                }
            } else {
                $responseJson = ['body' => $body];
            }
        }

        $payload = [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'request_json' => $request->all(),
            'response_json' => $responseJson,
            'http_status' => $response->getStatusCode(),
            'request_time' => (int) round((microtime(true) - $startedAt) * 1000),
            'correlation_id' => $response->headers->get('X-Correlation-ID'),
        ];

        try {
            if (config('error-handler.kibana_logging')) {
                (new KibanaLogger(
                    (string) config('error-handler.kibana_url'),
                    (int) config('error-handler.kibana_timeout', 2),
                    (array) config('error-handler.kibana_headers', []),
                ))->log($payload);
            } else {
                Log::info('HTTP request and response', $payload);
            }
        } catch (Throwable $exception) {
            Log::warning('Request/response logging failed', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $response;
    }
}
PHP;

        if (file_put_contents($path, $contents . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the middleware file: ' . $path);
        }

        $this->info('Error handler middleware created at ' . $path);
        $this->comment('Register LogRequestResponse globally or on the routes you want to log.');

        return self::SUCCESS;
    }
}
