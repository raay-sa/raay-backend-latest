<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Support extends Model
{
    protected $casts = [
        'status' => 'integer', // أو 'boolean' إذا أردت 
    ];

    // public function student()
    // {
    //     return $this->belongsTo(Student::class);
    // }

    public function user()
    {
        return $this->morphTo();
    }

}
