<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $fillable = ['phone', 'code', 'expired_at'];

    protected $casts = [
        'expired_at' => 'datetime',
    ];
}
