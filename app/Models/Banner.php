<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{

    protected $guarded = [];

    protected $casts = [
        'linked_at' => 'datetime',
        'min_students' => 'integer',
        'max_students' => 'integer',
    ];

    // Relationships
    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function interests()
    {
        return $this->hasMany(BannerInterest::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'banner_interests')
            ->withPivot(['notified_at'])
            ->withTimestamps();
    }

    // Accessors
    public function getInterestedStudentsCountAttribute()
    {
        return $this->interests()->count();
    }

    public function getCanBeLinkedAttribute()
    {
        return $this->status === 'active' &&
            $this->interested_students_count >= $this->min_students;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeLinked($query)
    {
        return $query->where('status', 'linked');
    }
}
