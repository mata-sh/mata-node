<?php

declare(strict_types=1);

namespace Mata\Watchers\Exceptions;

use Mata\Http\HttpException;

class DatabaseWatcherException extends HttpException
{
    public static function notConfigured(): self
    {
        return new self(
            message: 'Configure config/databases.json to enable database monitoring.',
            error: 'Database monitoring not configured',
            httpStatus: 404,
        );
    }

    /**
     * @param list<string> $available
     */
    public static function unknownDatabase(string $database, array $available): self
    {
        return new self(
            message: "Database '{$database}' is not monitored.",
            error: 'Unknown database',
            httpStatus: 404,
            context: [
                'database' => $database,
                'available' => $available,
            ]
        );
    }

    public static function invalidParameter(string $parameter, string $rule): self
    {
        return new self(
            message: "Query parameter '{$parameter}' {$rule}.",
            error: 'Invalid query parameter',
            httpStatus: 400,
            context: ['parameter' => $parameter]
        );
    }

    public static function unknownConnection(string $name): self
    {
        return new self(
            message: "Connection '{$name}' is not monitored.",
            error: 'Unknown connection',
            httpStatus: 404,
            context: ['connection' => $name],
        );
    }

    public static function connectionFailed(): self
    {
        return new self(
            message: 'Database connection failed',
            error: 'Database connection failed',
            httpStatus: 503,
        );
    }
}
