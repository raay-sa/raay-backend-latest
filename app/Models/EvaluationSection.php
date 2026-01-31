<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationSection extends Model
{
    public function questions()
    {
        return $this->hasMany(EvaluationQuestion::class, 'section_id');
    }

}
