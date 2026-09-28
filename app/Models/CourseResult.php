<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseResult extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'percentage' => 'decimal:2',
        'grade_point' => 'decimal:2',
        'remarks' => 'array',
    ];

    public function examinationCourse(): BelongsTo
    {
        return $this->belongsTo(ExaminationCourse::class);
    }
}
