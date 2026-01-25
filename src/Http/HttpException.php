<?php

declare(strict_types=1);

namespace Mata\Http;

use RuntimeException;
use Throwable;

class HttpException extends RuntimeException
{
    private int $httpStatus;
    private string $error;
    private array $context;

    public function __construct(
        string $message,
        string $error = 'Request error',
        int $httpStatus = 500,
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->error = $error;
        $this->context = $context;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
