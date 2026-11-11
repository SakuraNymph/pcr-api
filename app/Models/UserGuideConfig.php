<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGuideConfig extends Model
{
    /**
     * 新手指引配置表
     */
    protected $table = 'user_guide_configs';

    /**
     * 指定主键字段
     */
    protected $primaryKey = 'uid';

    /**
     * 主键不是自增 ID
     */
    public $incrementing = false;

    /**
     * 主键类型
     */
    protected $keyType = 'int';

    /**
     * 可以批量赋值的字段
     */
    protected $fillable = [
        'uid',
        'configuration',
    ];

    /**
     * 字段类型转换
     */
    protected function casts(): array
    {
        return [
            'configuration' => 'array',
        ];
    }

    /**
     * 新手指引位置
     */
    public const LOCATIONS = [
        'welcome',
        'list',
        'post',
        'team',
        'account',
        'group',
    ];

    /**
     * 默认配置
     *
     * true：不需要开启新手指引
     * false：用户跳过后，不再开启新手指引
     */
    public const DEFAULT_CONFIGURATION = [
        'welcome' => true,
        'list' => true,
        'post' => true,
        'team' => true,
        'account' => true,
        'group' => true,
    ];
}
