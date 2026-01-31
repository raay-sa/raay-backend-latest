<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramSection extends Model
{
    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function sessions()
    {
        return $this->hasMany(ProgramSession::class, 'section_id');
    }

    public function free_materials()
    {
        return $this->hasMany(FreeMaterial::class, 'section_id');
    }

    public function translation()
    {
        return $this->hasOne('App\Models\ProgramSectionTranslation', 'parent_id', 'id')->where('locale', app()->getLocale());
    }

    public function translations()
    {
        return $this->hasMany('App\Models\ProgramSectionTranslation', 'parent_id', 'id');
    }
}
