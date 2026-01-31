<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherNotificationSetting extends Model
{
    public function notificationSetting()
    {
        return $this->belongsTo(Teacher::class);
    }
}
