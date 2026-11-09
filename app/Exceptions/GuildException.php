<?php

namespace App\Exceptions;

use RuntimeException;

class GuildException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 409,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function data(): array
    {
        return $this->data;
    }

    public static function forbidden(string $message = '没有权限执行该操作'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = '公会不存在'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message, array $data = []): self
    {
        return new self($message, 409, $data);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }
}
