<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $casts = [
        'profit_percentage' => 'integer',
        'ant_media' => 'integer',
    ];
}
