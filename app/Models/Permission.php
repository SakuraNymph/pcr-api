<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['name', 'display_name', 'http_method', 'http_path', 'menu_id'];

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
