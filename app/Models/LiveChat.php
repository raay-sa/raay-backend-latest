<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveChat extends Model
{
    public function user()
    {
        return $this->morphTo();
    }
}
