<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAnswer extends Model
{
    public function examStudent()
    {
        return $this->belongsTo(ExamStudent::class, 'exam_student_id');
    }
}
