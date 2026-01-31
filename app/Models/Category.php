<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    // protected $with = ['teachers']; // to load relations in cache directly

    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'teacher_categories');
    }

    public function translation()
    {
        return $this->hasOne('App\Models\CategoryTranslation', 'parent_id', 'id')->where('locale', app()->getLocale());
    }

    public function translations()
    {
        return $this->hasMany('App\Models\CategoryTranslation', 'parent_id', 'id');
    }

}
