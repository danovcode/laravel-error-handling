<?php

declare(strict_types=1);

namespace BusinessErrorHandler;

use BusinessErrorHandler\Logger\KibanaLogger;
use BusinessErrorHandler\Logger\LoggerInterface;
use ErrorException;
use InvalidArgumentException;
use Throwable;

final class ErrorHandler
{
    private static array $config = [];
    private static ?LoggerInterface $logger = null;
    private static float $requestStartedAt = 0.0;
    private static bool $registered = false;
    private static bool $exceptionHandled = false;

    public static function register(array $config = []): void
    {
        $environment = $config['environment'] ?? 'production';
        if (!in_array($environment, ['development', 'production'], true)) {
            throw new InvalidArgumentException('Environment must be development or production.');
        }

        $logger = $config['logger'] ?? null;
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new InvalidArgumentException('The logger must implement the package LoggerInterface.');
        }

        if ($logger === null && ($config['kibana_logging'] ?? false)) {
            $endpoint = $config['kibana_url'] ?? getenv('KIBANA_URL') ?: 'http://localhost:9200/error-handler/_doc';
            $logger = new KibanaLogger(
                $endpoint,
                (int) ($config['kibana_timeout'] ?? 2),
                (array) ($config['kibana_headers'] ?? []),
            );
        }

        self::$config = $config + [
            'environment' => $environment,
            'default_message' => 'Internal Server Error',
            'exception_messages' => [],
        ];
        self::$logger = $logger;
        self::$requestStartedAt = microtime(true);
        self::$registered = true;
        self::$exceptionHandled = false;

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function render(Throwable $exception, ?string $correlationId = null): array
    {
        $correlationId ??= self::correlationId();
        $response = [
            'status' => false,
            'message' => self::publicMessage($exception),
            'correlation_id' => $correlationId,
        ];

        if ($exception instanceof BusinessValidationException && $exception->getData() !== null) {
            $response['data'] = $exception->getData();
        }

        $validationErrors = self::validationErrors($exception);
        $validationCount = array_sum(array_map('count', $validationErrors));
        if ($validationCount > 1) {
            $response['validation'] = $validationErrors;
        }

        if ((self::$config['environment'] ?? 'development') === 'development') {
            $response['exception'] = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        return $response;
    }

    public static function handleException(Throwable $exception): void
    {
        if (self::$exceptionHandled) {
            return;
        }

        self::$exceptionHandled = true;
        $correlationId = self::correlationId();
        $response = self::render($exception, $correlationId);
        self::logException($exception, $response, $correlationId);

        if (!headers_sent()) {
            http_response_code(self::httpStatus($exception));
            header('Content-Type: application/json; charset=utf-8');
            header('X-Correlation-ID: ' . $correlationId);
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleShutdown(): void
    {
        if (!self::$registered) {
            return;
        }

        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            self::handleException(new ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line'],
            ));
        }
    }

    private static function publicMessage(Throwable $exception): string
    {
        if ($exception instanceof BusinessValidationException) {
            return self::interpolateMessage($exception->getMessage(), $exception);
        }

        $configured = self::$config['exception_messages'] ?? [];
        $classes = array_merge(
            [$exception::class],
            array_values(class_parents($exception) ?: []),
            array_values(class_implements($exception) ?: []),
        );
        foreach ($classes as $class) {
            if (isset($configured[$class]['message']) && is_string($configured[$class]['message'])) {
                return self::interpolateMessage($configured[$class]['message'], $exception);
            }

            if (isset(self::$config[$class]['message']) && is_string(self::$config[$class]['message'])) {
                return self::interpolateMessage(self::$config[$class]['message'], $exception);
            }
        }

        if (isset($configured['*']['message']) && is_string($configured['*']['message'])) {
            return self::interpolateMessage($configured['*']['message'], $exception);
        }

        foreach (self::validationErrors($exception) as $messages) {
            if (isset($messages[0])) {
                return $messages[0];
            }
        }

        $defaultMessage = self::defaultExceptionMessage($exception);
        if ($defaultMessage !== null) {
            return self::interpolateMessage($defaultMessage, $exception);
        }

        if ((self::$config['environment'] ?? 'development') === 'development') {
            return $exception->getMessage();
        }

        return self::$config['default_message'] ?? 'Internal Server Error';
    }

    private static function validationErrors(Throwable $exception): array
    {
        if (strtolower((new \ReflectionClass($exception))->getShortName()) !== 'validationexception'
            || !method_exists($exception, 'errors')) {
            return [];
        }

        $errors = $exception->errors();
        if (!is_array($errors)) {
            return [];
        }

        $validation = [];
        foreach ($errors as $field => $messages) {
            if (!is_array($messages)) {
                $messages = [$messages];
            }

            $messages = array_values(array_filter($messages, 'is_string'));
            if ($messages !== []) {
                $validation[(string) $field] = $messages;
            }
        }

        return $validation;
    }

    private static function defaultExceptionMessage(Throwable $exception): ?string
    {
        $defaults = [
            'ModelNotFoundException' => 'No Result for Query [{model}].',
            'NotFoundHttpException' => 'The requested resource was not found.',
            'NotFoundException' => 'The requested resource was not found.',
            'MethodNotAllowedHttpException' => 'The HTTP method is not allowed for this resource.',
            'MethodNotAllowedException' => 'The HTTP method is not allowed for this resource.',
            'TooManyRequestsHttpException' => 'Too many requests. Please try again later.',
            'TooManyRequestsException' => 'Too many requests. Please try again later.',
            'BadRequestHttpException' => 'The request is invalid.',
            'BadRequestException' => 'The request is invalid.',
            'UnauthorizedHttpException' => 'Authentication is required.',
            'UnauthorizedException' => 'Authentication is required.',
            'AuthenticationException' => 'Authentication is required.',
            'ForbiddenHttpException' => 'You are not authorized to perform this action.',
            'ForbiddenException' => 'You are not authorized to perform this action.',
            'AuthorizationException' => 'You are not authorized to perform this action.',
            'PDOException' => 'A database operation failed.',
            'InvalidArgumentException' => 'The provided input is invalid.',
            'OutOfBoundsException' => 'The requested item was not found.',
            'OutOfRangeException' => 'The requested value is out of range.',
            'LengthException' => 'The provided value has an invalid length.',
            'DomainException' => 'The provided value is not valid for this operation.',
            'TypeError' => 'The operation received a value of an invalid type.',
            'ParseError' => 'The request could not be processed.',
            'DivisionByZeroError' => 'The operation could not be completed.',
            'ArithmeticError' => 'The operation could not be completed.',
            'RuntimeException' => 'The operation could not be completed.',
            'UnexpectedValueException' => 'The operation returned an unexpected result.',
            'LogicException' => 'The operation could not be completed.',
            'ErrorException' => 'The operation could not be completed.',
            'Error' => 'The operation could not be completed.',
            'Exception' => 'The operation could not be completed.',
        ];

        foreach (array_merge(
            [(new \ReflectionClass($exception))->getShortName()],
            array_map(
                static fn (string $class): string => (new \ReflectionClass($class))->getShortName(),
                array_values(class_parents($exception) ?: []),
            ),
        ) as $shortName) {
            if (isset($defaults[$shortName])) {
                return $defaults[$shortName];
            }
        }

        return null;
    }

    private static function interpolateMessage(string $message, Throwable $exception): string
    {
        if (!method_exists($exception, 'getModel')) {
            return $message;
        }

        $model = $exception->getModel();
        if (!is_string($model) || $model === '') {
            return $message;
        }

        $separator = strrpos($model, '\\');
        $modelName = $separator === false ? $model : substr($model, $separator + 1);

        return strtr($message, [
            '{model}' => $modelName,
            '{model_name}' => $modelName,
            ':model' => $modelName,
            '[Model Name]' => $modelName,
        ]);
    }

    private static function httpStatus(Throwable $exception): int
    {
        if ($exception instanceof BusinessValidationException) {
            return 422;
        }

        if (method_exists($exception, 'getStatusCode')) {
            $status = $exception->getStatusCode();
            if (is_int($status) && $status >= 400 && $status <= 599) {
                return $status;
            }
        }

        return match (strtolower((new \ReflectionClass($exception))->getShortName())) {
            'badrequestexception' => 400,
            'unauthorizedexception' => 401,
            'forbiddenexception' => 403,
            'notfoundexception' => 404,
            'methodnotallowedexception' => 405,
            'toomanyrequestsexception' => 429,
            default => 500,
        };
    }

    private static function correlationId(): string
    {
        $incoming = $_SERVER['HTTP_X_CORRELATION_ID'] ?? null;
        if (is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $incoming) === 1) {
            return $incoming;
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private static function logException(Throwable $exception, array $response, string $correlationId): void
    {
        if (self::$logger === null) {
            error_log(sprintf(
                '[%s] %s in %s:%d%s%s',
                $correlationId,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                PHP_EOL,
                $exception->getTraceAsString(),
            ));

            return;
        }

        try {
            $request = self::requestContext();
            $payload = [
                'url' => $request['url'],
                'request_json' => $request['request_json'],
                'response_json' => $response,
                'http_status' => self::httpStatus($exception),
                'request_time' => $request['request_time'],
                'correlation_id' => $correlationId,
                'exception' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ],
            ];

            self::$logger->log($payload);
        } catch (Throwable $loggingError) {
            error_log(sprintf(
                '[%s] Error handler logger failed: %s in %s:%d',
                $correlationId,
                $loggingError->getMessage(),
                $loggingError->getFile(),
                $loggingError->getLine(),
            ));
        }
    }

    private static function requestContext(): array
    {
        $provider = self::$config['request_context'] ?? null;
        if (is_callable($provider)) {
            $context = $provider();
            if (!is_array($context)) {
                throw new InvalidArgumentException('The request_context callback must return an array.');
            }

            return [
                'url' => (string) ($context['url'] ?? ''),
                'request_json' => $context['request_json'] ?? [],
                'request_time' => max(0, (int) ($context['request_time'] ?? round((microtime(true) - self::$requestStartedAt) * 1000))),
            ];
        }

        $body = file_get_contents('php://input');
        $decoded = $body !== false && $body !== '' ? json_decode($body, true) : [];

        return [
            'url' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'request_json' => is_array($decoded) ? $decoded : [],
            'request_time' => (int) round((microtime(true) - self::$requestStartedAt) * 1000),
        ];
    }
}
