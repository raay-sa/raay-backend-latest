<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PhpParser\Node\Expr\Assign;
use Illuminate\Support\Str;

class Program extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $with = ['category', 'teacher']; // to load relations in cache directly
    protected $casts = [
        'learning' => 'array',
        'requirement' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($program) {
            if (empty($program->transaction_number)) {
                $program->transaction_number = self::generateTransactionNumber();
            }
        });
    }

    /**
     * Generate a unique transaction number
     */
    protected static function generateTransactionNumber()
    {
        do {
            $number = 'PROG-' . strtoupper(Str::random(8)) . '-' . date('Ymd');
        } while (self::where('transaction_number', $number)->exists());

        return $number;
    }


    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function sections()
    {
        return $this->hasMany(ProgramSection::class, 'program_id');
    }

    public function sessions()
    {
        return $this->hasManyThrough(
            ProgramSession::class,  // الموديل النهائي
            ProgramSection::class,  // الموديل الوسيط
            'program_id',    // FK في sections
            'section_id',    // FK في sessions
            'id',            // PK في programs
            'id'             // PK في sections
        );
    }


    public function exams()
    {
        return $this->hasMany(Exam::class, 'program_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }


    public function evaluationForms()
    {
        return $this->belongsToMany(EvaluationForm::class, 'evalution_programs', 'program_id', 'form_id')
            ->withPivot(['snapshot', 'assigned_at'])
            ->withTimestamps();
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }


    public function assignment_solutions()
    {
        return $this->hasManyThrough(
            AssignmentSolution::class,       // الحلول
            Assignment::class,     // المهام
            'program_id',         // المفتاح الأجنبي في assignments الذي يشير إلى program
            'assignment_id',     // المفتاح الأجنبي في solutions الذي يشير إلى assignment
            'id',               // المفتاح المحلي في programs
            'id'               // المفتاح المحلي في assignments
        );
    }

    public function exam_answers()
    {
        return $this->hasManyThrough(
            ExamStudent::class,       // الحلول
            Exam::class,     // المهام
            'program_id',   // المفتاح الأجنبي في exams الذي يشير إلى program
            'exam_id',     // المفتاح الأجنبي في answers الذي يشير إلى exam
            'id',         // المفتاح المحلي في programs
            'id'         // المفتاح المحلي في exams
        );
    }

    public function translation()
    {
        return $this->hasOne('App\Models\ProgramTranslation', 'parent_id', 'id')->where('locale', app()->getLocale());
    }

    public function translations()
    {
        return $this->hasMany('App\Models\ProgramTranslation', 'parent_id', 'id');
    }

    public function isOnsite()
    {
        return $this->type === 'onsite';
    }

    public function isLive()
    {
        return $this->type === 'live';
    }

    public function isRegistered()
    {
        return $this->type === 'registered';
    }
}
