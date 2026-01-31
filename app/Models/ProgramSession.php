<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramSession extends Model
{
    protected $casts = [
        'files' => 'json',
        'video_duration' => 'integer'
    ];

    public function section()
    {
        return $this->belongsTo(ProgramSection::class, 'section_id');
    }

    protected $appends = ['formatted_video_duration'];

    // public function getFormattedVideoDurationAttribute()
    // {
    //     return $this->video_duration
    //         ? formatDuration($this->video_duration)
    //         : '0:00';
    // }

    public function getFormattedVideoDurationAttribute()
{
    if (!$this->video_duration) {
        return '00:00:00';
    }

    // If stored as seconds
    $seconds = $this->video_duration;
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

    public function translation()
    {
        return $this->hasOne('App\Models\ProgramSessionTranslation', 'parent_id', 'id')->where('locale', app()->getLocale());
    }

    public function translations()
    {
        return $this->hasMany('App\Models\ProgramSessionTranslation', 'parent_id', 'id');
    }
}

