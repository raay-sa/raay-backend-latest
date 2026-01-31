<?php

namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $hidden = [
        'password',
    ];

    public function role()
    {
        return $this->hasOne('App\Models\Role', 'id', 'role_id');
    }

    public function refresh_tokens()
    {
        return $this->morphMany(RefreshToken::class, 'user');
    }

    protected static function booted()
    {
        static::deleting(function ($student) {
            $student->refresh_tokens()->delete();
        });
    }
}
