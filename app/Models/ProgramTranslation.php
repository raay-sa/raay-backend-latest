<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramTranslation extends Model
{
    protected $casts = [
        'learning' => 'array',
        'requirement' => 'array',
        'main_axes' => 'array',
    ];

}
