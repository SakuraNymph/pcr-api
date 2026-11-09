<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRolePool extends Model
{
    /**
     * 数据表
     */
    protected $table = 'user_role_pools';

    /**
     * 允许批量赋值的字段
     */
    protected $fillable = [
        'uid',
        'nickname',
        'roles',
        'coin',
        'status',
    ];

    /**
     * 字段类型转换
     */
    protected $casts = [
        'id' => 'integer',
        'uid' => 'integer',
        'roles' => 'array',
        'coin' => 'array',
        'status' => 'integer',
    ];

    /**
     * 所属用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }
}
