<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationResponse extends Model
{
    public function form()
    {
        return $this->belongsTo(EvaluationForm::class, 'form_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function answers()
    {
        return $this->hasMany(EvaluationAnswer::class, 'response_id');
    }
}
