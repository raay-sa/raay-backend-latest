<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommonQuestion extends Model
{
      protected $casts = [
        'status' => 'integer',
    ];
}
