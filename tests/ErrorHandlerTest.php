<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Tests;

use BusinessErrorHandler\BusinessValidationException;
use BusinessErrorHandler\ErrorHandler;
use BusinessErrorHandler\Logger\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testProductionHidesUnexpectedExceptionMessage(): void
    {
        ErrorHandler::register(['environment' => 'production']);

        $response = ErrorHandler::render(new RuntimeException('database password leaked'), 'test-correlation');

        self::assertSame(false, $response['status']);
        self::assertArrayNotHasKey('success', $response);
        self::assertSame('The operation could not be completed.', $response['message']);
        self::assertSame('test-correlation', $response['correlation_id']);
        self::assertArrayNotHasKey('exception', $response);
    }

    public function testConfiguredMessageIsSafeToExposeInProduction(): void
    {
        ErrorHandler::register([
            'environment' => 'production',
            RuntimeException::class => ['message' => 'System processing failed.'],
        ]);

        self::assertSame(
            'System processing failed.',
            ErrorHandler::render(new RuntimeException('private detail'))['message'],
        );
    }

    public function testModelNotFoundUsesSafeDefaultWithShortModelName(): void
    {
        ErrorHandler::register(['environment' => 'production']);

        $response = ErrorHandler::render(
            new ModelNotFoundException('No query results for model [App\\Models\\Balance].', 'App\\Models\\Balance'),
        );

        self::assertSame('No Result for Query [Balance].', $response['message']);
        self::assertStringNotContainsString('No query results', $response['message']);
    }

    public function testModelNotFoundMessageCanBeCustomizedWithModelPlaceholder(): void
    {
        ErrorHandler::register([
            'environment' => 'production',
            'exception_messages' => [
                ModelNotFoundException::class => ['message' => 'hasil Dari [Model Name] tidak di temukan'],
            ],
        ]);

        $response = ErrorHandler::render(
            new ModelNotFoundException('No query results for model [App\\Models\\Balance].', 'App\\Models\\Balance'),
        );

        self::assertSame('hasil Dari Balance tidak di temukan', $response['message']);
    }

    public function testBusinessValidationMessageIsSafeToExposeInProduction(): void
    {
        ErrorHandler::register(['environment' => 'production']);

        self::assertSame(
            'Insufficient balance.',
            ErrorHandler::render(new BusinessValidationException('Insufficient balance.'))['message'],
        );
    }

    public function testDevelopmentIncludesExceptionDetails(): void
    {
        ErrorHandler::register(['environment' => 'development']);
        $exception = new RuntimeException('diagnostic detail');

        $response = ErrorHandler::render($exception);

        self::assertSame('The operation could not be completed.', $response['message']);
        self::assertSame($exception::class, $response['exception']['class']);
        self::assertSame('diagnostic detail', $response['exception']['message']);
        self::assertArrayHasKey('trace', $response['exception']);
    }

    public function testLoggerReceivesRequestAndCorrelationDetails(): void
    {
        $logger = new class implements LoggerInterface {
            public array $payload = [];

            public function log(array $payload): void
            {
                $this->payload = $payload;
            }
        };
        $previousCorrelationId = $_SERVER['HTTP_X_CORRELATION_ID'] ?? null;
        $_SERVER['HTTP_X_CORRELATION_ID'] = 'request-123';
        ErrorHandler::register([
            'environment' => 'production',
            'logger' => $logger,
            'request_context' => static fn (): array => [
                'url' => '/api/v1/payment',
                'request_json' => ['amount' => 25],
                'request_time' => 120,
            ],
        ]);

        ob_start();
        ErrorHandler::handleException(new BusinessValidationException('Insufficient balance.'));
        $response = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
        if ($previousCorrelationId === null) {
            unset($_SERVER['HTTP_X_CORRELATION_ID']);
        } else {
            $_SERVER['HTTP_X_CORRELATION_ID'] = $previousCorrelationId;
        }

        self::assertSame('request-123', $response['correlation_id']);
        self::assertSame('/api/v1/payment', $logger->payload['url']);
        self::assertSame(['amount' => 25], $logger->payload['request_json']);
        self::assertSame(422, $logger->payload['http_status']);
        self::assertSame(120, $logger->payload['request_time']);
        self::assertSame('request-123', $logger->payload['correlation_id']);
    }
}

final class ModelNotFoundException extends RuntimeException
{
    public function __construct(string $message, private readonly string $model)
    {
        parent::__construct($message);
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
