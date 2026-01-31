<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $guarded = [];
    
    protected $casts = [
        'users_type' => 'array',
    ];
}
