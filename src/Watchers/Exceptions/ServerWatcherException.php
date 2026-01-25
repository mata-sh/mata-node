<?php

declare(strict_types=1);

namespace Mata\Watchers\Exceptions;

use Mata\Http\HttpException;

class ServerWatcherException extends HttpException
{
    public static function commandFailed(string $command): self
    {
        return new self(
            message: "Failed to execute command: {$command}",
            error: 'Server command failed',
            httpStatus: 500,
            context: ['command' => $command]
        );
    }
}
