<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $casts = [
        'reference' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'program_transactions', 'transaction_id', 'program_id');
    }
}
