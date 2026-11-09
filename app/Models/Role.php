<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'sort',
        'status',
    ];

    public function admins()
    {
        return $this->belongsToMany(Admin::class, 'admin_role');
    }

    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'role_menu');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }
}