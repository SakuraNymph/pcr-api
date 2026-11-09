<?php

namespace App\Exceptions;

use Exception;

class PasswordResetException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 422
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
