<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 按 UTF-8 字节长度校验昵称。
 */
class NicknameByteLength implements ValidationRule
{
    public function __construct(
        private readonly int $minBytes = 1,
        private readonly int $maxBytes = 24,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $length = strlen((string) $value);

        if ($length < $this->minBytes || $length > $this->maxBytes) {
            $fail("昵称长度需为 {$this->minBytes}–{$this->maxBytes} 个字节");
        }
    }
}
