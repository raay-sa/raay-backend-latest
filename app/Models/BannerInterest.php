<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BannerInterest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    // Accessor to get user name
    public function getUserNameAttribute()
    {
        return $this->student ? $this->student->name : $this->name;
    }

    // Accessor to get user email 
    public function getUserEmailAttribute()
    {
        return $this->student ? $this->student->email : $this->email;
    }

    // Accessor to get user phone 
    public function getUserPhoneAttribute()
    {
        return $this->student ? $this->student->phone : $this->phone;
    }

    // Relationships
    public function banner()
    {
        return $this->belongsTo(Banner::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    // Scopes
    public function scopeNotified($query)
    {
        return $query->whereNotNull('notified_at');
    }

    public function scopeNotNotified($query)
    {
        return $query->whereNull('notified_at');
    }
}
