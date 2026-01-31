<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    public function answers()
    {
        return $this->hasMany(ExamStudent::class, 'exam_id');
    }

    public function questions()
    {
        return $this->hasMany(ExamQuestion::class, 'exam_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'exam_student')  // in pivot table
            ->withTimestamps();
    }
}
