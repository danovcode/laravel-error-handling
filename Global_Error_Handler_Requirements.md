# Global Error Handler PHP Library - Requirements Specification

## Overview

Create a reusable PHP library that provides centralized error handling, exception management, API-safe responses, business validation exceptions, and optional Kibana logging support.

The library should be framework-agnostic and usable in:

- Native PHP
- Laravel
- Lumen
- Slim
- Custom Service-Repository Architecture
- Microservices

---

# Core Features

## 1. Global Error Handling

The library must register and handle all common PHP exceptions and errors.

### Supported Exception Types

#### Runtime Exceptions

- RuntimeException
- UnexpectedValueException
- LogicException
- InvalidArgumentException
- LengthException
- DomainException
- OutOfBoundsException
- OutOfRangeException

#### Database Exceptions

- PDOException

#### HTTP Exceptions

- BadRequestException
- UnauthorizedException
- ForbiddenException
- NotFoundException
- MethodNotAllowedException
- TooManyRequestsException

#### System Errors

- Error
- TypeError
- ParseError
- ArithmeticError
- DivisionByZeroError

#### Generic Exceptions

- Exception
- Throwable

---

# 2. Customizable Error Messages

Every exception type must be configurable.

Example:

```php
ErrorHandler::register([
    RuntimeException::class => [
        'message' => 'System processing failed.'
    ],
    PDOException::class => [
        'message' => 'Database operation failed.'
    ]
]);
```

Expected API Response:

```json
{
    "success": false,
    "message": "Database operation failed."
}
```

---

# 3. Secure Exception Exposure

The library must prevent raw exception messages from being exposed to API consumers.

### Production Response

```json
{
    "success": false,
    "message": "Internal Server Error"
}
```

### Internal Logging

The original exception details must still be logged.

```php
$e->getMessage();
$e->getFile();
$e->getLine();
$e->getTraceAsString();
```

---

# 4. BusinessValidation Exception

Create a special exception called:

```php
BusinessValidationException
```

Purpose:

Allow developers to throw validation errors related to business rules.

Example:

```php
throw new BusinessValidationException(
    'Insufficient balance to perform transaction.'
);
```

### Behavior

Unlike system exceptions, the message from this exception is safe to expose to API responses.

```json
{
    "success": false,
    "message": "Insufficient balance to perform transaction."
}
```

---

# 5. Environment Mode

Support:

- development
- production

Development mode returns full exception details.

Production mode returns only safe messages.

---

# 6. Optional Kibana Logging

Users must be able to enable or disable Kibana logging.

```php
ErrorHandler::register([
    'kibana_logging' => true
]);
```

---

# 7. Laravel Command Generator

Provide an Artisan command:

```bash
php artisan make:errorhandler
```

With Kibana:

```bash
php artisan make:errorhandler --logkibana
```

---

# 8. Kibana Log Structure

```json
{
    "url": "/api/v1/payment",
    "request_json": {},
    "response_json": {},
    "http_status": 500,
    "request_time": 120
}
```

Fields:

- url
- request_json
- response_json
- http_status
- request_time (milliseconds)

---

# 9. Correlation ID Support

```json
{
    "correlation_id": "2ee8d8bd-c0f1-4e3c-bbf8-f648f6c53c9d"
}
```

Every error and request log should contain the correlation ID.

---

# 10. Extensible Logger System

```php
interface LoggerInterface
{
    public function log(array $payload): void;
}
```

Supported implementations:

- KibanaLogger
- MonologLogger
- CustomLogger

---

# Expected Package Usage

## Basic

```php
ErrorHandler::register();
```

## Production

```php
ErrorHandler::register([
    'environment' => 'production'
]);
```

## Kibana Enabled

```php
ErrorHandler::register([
    'environment' => 'production',
    'kibana_logging' => true
]);
```

## Business Validation

```php
throw new BusinessValidationException(
    'Insufficient balance.'
);
```

---

# Non-Functional Requirements

- PHP 8.1+
- PSR-4 Autoloading
- PSR-3 Logging Compatible
- Framework Agnostic
- API Safe Error Responses
- Unit Test Coverage
- Composer Installable
- Packagist Ready
- SOLID Principles
- Clean Architecture Friendly
- Service Repository Pattern Friendly

---

# Recommended Package Name

```text
dwiky-danov/business-error-handler
```
