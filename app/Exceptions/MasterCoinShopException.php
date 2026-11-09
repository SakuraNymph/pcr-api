<?php

namespace App\Exceptions;

use Exception;

class MasterCoinShopException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
