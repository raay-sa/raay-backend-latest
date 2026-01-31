<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationForm extends Model
{
    public function sections()
    {
        return $this->hasMany(EvaluationSection::class, 'form_id');
    }

    // public function program()
    // {
    //     return $this->belongsTo(Program::class, 'program_id');
    // }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'evalution_programs', 'form_id', 'program_id')
            ->withPivot(['snapshot', 'assigned_at'])
            ->withTimestamps();
    }


}
