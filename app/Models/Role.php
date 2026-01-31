<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public function admins()
    {
        return $this->hasMany('App\Models\Admin', 'role_id', 'id');
    }

    public function individuals()
    {
        return $this->hasMany('App\Models\Individual', 'role_id', 'id');
    }

    public function companies()
    {
        return $this->hasMany('App\Models\Company', 'role_id', 'id');
    }
}
