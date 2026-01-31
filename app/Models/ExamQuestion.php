<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model
{
    public function options()
    {
        return $this->hasMany(ExamQuestionOption::class, 'question_id');
    }
}
