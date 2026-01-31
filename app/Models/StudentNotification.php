<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentNotification extends Model
{
    protected $casts = [
        'is_read' => 'integer',
    ];

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }
}
