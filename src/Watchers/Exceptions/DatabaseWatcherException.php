<?php

declare(strict_types=1);

namespace Mata\Watchers\Exceptions;

use Mata\Http\HttpException;

class DatabaseWatcherException extends HttpException
{
    public static function notFound(string $databaseName, array $available): self
    {
        return new self(
            message: "Database connection '{$databaseName}' not found.",
            error: 'Unknown database',
            httpStatus: 404,
            context: [
                'database' => $databaseName,
                'available' => $available,
            ]
        );
    }

    public static function connectionFailed(string $databaseName, string $host, string $port, string $database, string $details): self
    {
        return new self(
            message: "Failed to connect to database '{$databaseName}' ({$database} on {$host}:{$port}): {$details}",
            error: 'Database connection failed',
            httpStatus: 503,
            context: [
                'database' => $databaseName,
                'host' => $host,
                'port' => $port,
            ]
        );
    }
}
