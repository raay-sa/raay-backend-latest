<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentNotificationSetting extends Model
{
    public function notificationSetting()
    {
        return $this->belongsTo(Student::class);
    }
}
