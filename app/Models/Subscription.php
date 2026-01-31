<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $casts = [
        'start_date' => 'date',
        'expire_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function isActive()
    {
        return $this->status === 'active' && 
               (!$this->expire_date || !$this->expire_date->isPast());
    }

    public function isExpired()
    {
        return $this->status === 'expired' || 
               ($this->expire_date && $this->expire_date->isPast());
    }

    public function isBanned()
    {
        return $this->status === 'banned';
    }

    public function canAccess()
    {
        return $this->isActive() && !$this->isBanned();
    }
}
