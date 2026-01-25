<?php

declare(strict_types=1);

namespace Mata\Watchers\Exceptions;

use Mata\Http\HttpException;

class AppWatcherException extends HttpException
{
    public static function notFound(string $app): self
    {
        return new self(
            message: 'Unknown app',
            error: 'Unknown app',
            httpStatus: 404,
            context: ['app' => $app]
        );
    }
}
