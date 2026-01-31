<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionDiscussion extends Model
{
    public function student()
    {
        return $this->belongsTo(Student::class, 'sender_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'sender_id');
    }
}
