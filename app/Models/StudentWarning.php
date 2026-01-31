<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentWarning extends Model
{
    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
