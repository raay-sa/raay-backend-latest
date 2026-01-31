<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationQuestion extends Model
{
    public function section()
    {
        return $this->belongsTo(EvaluationSection::class, 'section_id');
    }

    public function options()
    {
        return $this->hasMany(EvalutionOption::class, 'question_id');
    }

}
