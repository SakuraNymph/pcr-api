<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    /**
     * 日志表
     */
    protected $table = 'system_logs';

    /**
     * 系统日志只有 created_at，没有 updated_at
     */
    public const UPDATED_AT = null;

    /**
     * 可批量赋值字段
     */
    protected $fillable = [
        'admin_id',
        'admin_username',
        'module',
        'action',
        'target_type',
        'target_id',
        'description',
        'method',
        'path',
        'request_data',
        'changes',
        'result',
        'error_message',
        'ip',
        'user_agent',
    ];

    /**
     * JSON 字段自动转换为数组
     */
    protected function casts(): array
    {
        return [
            'request_data' => 'array',
            'changes' => 'array',
            'result' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}