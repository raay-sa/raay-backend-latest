<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreeMaterial extends Model
{
    protected $casts = [
        'files' => 'array',
        'video_duration' => 'integer'
    ];

    public function section()
    {
        return $this->belongsTo(ProgramSection::class, 'section_id');
    }

    protected $appends = ['formatted_video_duration'];

    public function getFormattedVideoDurationAttribute()
    {
        return $this->video_duration
            ? formatDuration($this->video_duration)
            : '0:00';
    }

}
