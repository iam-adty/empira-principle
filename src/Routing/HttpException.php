<?php

declare(strict_types=1);

namespace IamAdty\Empira\Routing;

use IamAdty\Empira\Exception;

/**
 * An HTTP status the application wants to return.
 *
 * Throwing this is how a controller or component says "stop, and answer with
 * this status" without assembling a response by hand. The framework turns it
 * into a real response, optionally through an error view.
 *
 *     throw HttpException::notFound('That todo is gone.');
 */
final class HttpException extends Exception
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message !== '' ? $message : 'Not Found');
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message !== '' ? $message : 'Forbidden');
    }

    public static function unauthorized(string $message = ''): self
    {
        return new self(401, $message !== '' ? $message : 'Unauthorized');
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message !== '' ? $message : 'Bad Request');
    }

    /**
     * A plain-English name for a status code, for when there is no message.
     */
    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => $status >= 500 ? 'Server Error' : 'Error',
        };
    }
}