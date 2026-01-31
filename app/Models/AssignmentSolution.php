<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentSolution extends Model
{
    protected $casts = [
        'grade' => 'integer',
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
