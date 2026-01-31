<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $casts = [
        'status' => 'integer', // أو 'boolean' إذا أردت
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function getWith()
    {
        return ['program'];
    }
}
