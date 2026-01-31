<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Student extends Authenticatable
{
    use HasApiTokens, Notifiable;
    protected $casts = [
        'is_approved' => 'integer', // أو 'boolean' إذا أردت
    ];

    public function notification_setting()
    {
        return $this->hasOne(StudentNotificationSetting::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function exams()
    {
        return $this->belongsToMany(Exam::class, 'exam_student')
            ->withTimestamps();
    }

    public function notifications()
    {
        return $this->hasMany(StudentNotification::class);
    }

    public function liveChats()
    {
        return $this->morphMany(LiveChat::class, 'user');
    }

    public function supports()
    {
        return $this->morphMany(Support::class, 'user');
    }

    public function refresh_tokens()
    {
        return $this->morphMany(RefreshToken::class, 'user');
    }

    protected static function booted()
    {
        static::deleting(function ($student) {
            $student->supports()->delete();
            $student->refresh_tokens()->delete();
        });
    }

    public function assignment_solutions()
    {
        return $this->hasMany(AssignmentSolution::class);
    }

    public function exam_answers()
    {
        return $this->hasMany(ExamStudent::class);
    }

    public function session_views()
    {
        return $this->hasMany(SessionView::class);
    }

    public function createdBy()
    {
        // return $this->morphTo();
        return $this->morphTo(__FUNCTION__, 'created_by_type', 'created_by_id');
    }

    public function bannerInterests()
    {
        return $this->hasMany(BannerInterest::class);
    }

    public function interestedBanners()
    {
        return $this->belongsToMany(Banner::class, 'banner_interests')
            ->withPivot(['notified_at'])
            ->withTimestamps();
    }
}
