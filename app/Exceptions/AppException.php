<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a request breaks a business rule, e.g. "marks are locked" or
 * "weights must add up to 100".
 *
 * bootstrap/app.php turns it into a JSON response like:
 *   {"error": {"code": "marks_not_accepted", "message": "...", "details": {...}}}
 *
 * Use the helpers so the HTTP status is always right:
 *   throw AppException::conflict('...', 'some_code');       // 409: not allowed in the current state
 *   throw AppException::unprocessable('...', 'some_code');  // 422: the input is invalid
 *   throw AppException::notFound('...');                     // 404
 */
class AppException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'error',
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message, string $code = 'conflict', array $details = []): self
    {
        return new self($message, $code, 409, $details);
    }

    public static function unprocessable(string $message, string $code = 'unprocessable', array $details = []): self
    {
        return new self($message, $code, 422, $details);
    }

    public static function notFound(string $message, string $code = 'not_found'): self
    {
        return new self($message, $code, 404);
    }
}
