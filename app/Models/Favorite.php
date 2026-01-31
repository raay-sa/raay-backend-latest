<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}
