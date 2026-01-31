<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotificationSetting extends Model
{
    public function notificationSetting()
    {
        return $this->belongsTo(Admin::class);
    }
}
