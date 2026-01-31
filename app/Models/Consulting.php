<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consulting extends Model
{
    protected $casts = [
        // 'files' => 'array',
        'status' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
