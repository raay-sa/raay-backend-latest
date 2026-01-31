<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyTerms extends Model
{
    protected $casts = [
        'users_type' => 'array',
    ];

}
