<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Teacher extends Authenticatable
{
    use HasApiTokens, Notifiable;
    protected $casts = [
        'is_approved' => 'integer', // أو 'boolean' إذا أردت
    ];

    // protected $with = ['categories', 'programs', 'assignments']; // to load relations in cache directly

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'teacher_categories', 'teacher_id', 'category_id');
    }

    public function programs()
    {
        return $this->hasMany(Program::class);
    }

    public function assignments()
    {
        return $this->hasManyThrough(
            Assignment::class,   // النهائي: الجدول الذي تريد الوصول له
            Program::class,      // الوسيط: الجدول الذي يربطك بالهدف
            'teacher_id',        // المفتاح الخارجي في جدول Program الذي يشير إلى Teacher
            'program_id',        // المفتاح الخارجي في جدول Assignment الذي يشير إلى Program
            'id',                // المفتاح المحلي في جدول Teacher (افتراضي: id)
            'id'                 // المفتاح المحلي في جدول Program (افتراضي: id)
        );
    }

    public function exams()
    {
        return $this->hasManyThrough(
            Exam::class,   // النهائي: الجدول الذي تريد الوصول له
            Program::class,      // الوسيط: الجدول الذي يربطك بالهدف
            'teacher_id',        // المفتاح الخارجي في جدول Program الذي يشير إلى Teacher
            'program_id',        // المفتاح الخارجي في جدول Assignment الذي يشير إلى Program
            'id',                // المفتاح المحلي في جدول Teacher (افتراضي: id)
            'id'                 // المفتاح المحلي في جدول Program (افتراضي: id)
        );
    }

    public function notifications()
    {
        return $this->hasMany(TeacherNotification::class);
    }

    public function notification_setting()
    {
        return $this->hasOne(TeacherNotificationSetting::class);
    }

    public function liveChats()
    {
        return $this->morphMany(LiveChat::class, 'user');
    }

    public function refresh_tokens()
    {
        return $this->morphMany(RefreshToken::class, 'user');
    }

    public function supports()
    {
        return $this->morphMany(Support::class, 'user');
    }

    public function students()
    {
        // return $this->morphMany(Student::class, 'createdBy');
        return $this->morphMany(Student::class, 'createdBy', 'created_by_type', 'created_by_id');
    }

    protected static function booted()
    {
        static::deleting(function ($teacher) {
            $teacher->supports()->delete();
            $teacher->refresh_tokens()->delete();
               // حذف الطلاب اللي هو أنشأهم
            $teacher->students()->delete();
        });
    }

}


