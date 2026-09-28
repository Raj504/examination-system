<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    protected $fillable = ['examination_id', 'examination_course_id', 'student_id'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function examinationCourse(): BelongsTo
    {
        return $this->belongsTo(ExaminationCourse::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }
}
