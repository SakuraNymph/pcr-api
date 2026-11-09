<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Boss extends Model
{
    protected $table = 'bosses';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';
}
