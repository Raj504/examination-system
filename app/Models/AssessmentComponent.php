<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentComponent extends Model
{
    protected $fillable = ['examination_course_id', 'code', 'name', 'max_marks', 'weight', 'min_pass_marks'];

    protected $casts = [
        'max_marks' => 'decimal:2',
        'weight' => 'decimal:2',
        'min_pass_marks' => 'decimal:2',
    ];

    public function examinationCourse(): BelongsTo
    {
        return $this->belongsTo(ExaminationCourse::class);
    }
}
