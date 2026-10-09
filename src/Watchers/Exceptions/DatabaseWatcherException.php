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

    public static function connectionFailed(string $host, string $port, string $details): self
    {
        return new self(
            message: "Failed to connect to the database server on {$host}:{$port}: {$details}",
            error: 'Database connection failed',
            httpStatus: 503,
            context: [
                'host' => $host,
                'port' => $port,
            ]
        );
    }

    public static function queryFailed(string $details): self
    {
        return new self(
            message: "Database query failed: {$details}",
            error: 'Database query failed',
            httpStatus: 503,
        );
    }
}
