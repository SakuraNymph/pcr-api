<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorToken extends Model
{
    /**
     * 允许批量赋值的字段
     */
    protected $fillable = [
        'uid',
        'token_hash',
        'last_used_at',
        'expires_at',
    ];

    /**
     * 字段类型转换
     */
    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    /**
     * 计算令牌哈希。
     *
     * 令牌线格式为 {visitor_tokens.id}|{random_hex}，只对明文后半段取 sha256。
     */
    public static function hashToken(string $token): string
    {
        $plain = str_contains($token, '|')
            ? explode('|', $token, 2)[1]
            : $token;

        return hash('sha256', $plain);
    }
}
