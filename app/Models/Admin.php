<?php

namespace App\Models;

use App\Models\Role;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;

    // 注意：如果你之前改过 $table，仍保持：
    protected $table = 'admins';

    /**
     * 允许批量赋值（mass assignment）的字段
     * Laravel 会在 `Admin::create([...])` 时自动填充
     */
    protected $fillable = [
        'username',
        'password',
        'nickname',
        'status',
    ];

    /**
     * 隐藏不需要返回给前端的字段
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 为 password 添加「setter」——自动 bcrypt
     */
    public function setPasswordAttribute($value)
    {
        // 只在值非空时进行 hash，防止意外把已经 hash 的再次 hash
        $this->attributes['password'] = $value ? bcrypt($value) : null;
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'admin_role');
    }
}