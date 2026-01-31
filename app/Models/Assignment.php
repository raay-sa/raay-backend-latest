<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function solutions()
    {
        return $this->hasMany(AssignmentSolution::class);
    }
}





// <?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Model;

// class Assignment extends Model
// {
//     protected $with = ['program']; // to load relations in cache directly

//     public function program()
//     {
//         return $this->belongsTo(Program::class);
//     }

//     // للوصول للمدرس عبر البرنامج
//     public function teacher()
//     {
//         return $this->program()->teacher();
//     }
// }
