<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExaminationCourse extends Model
{
    protected $fillable = ['examination_id', 'course_id', 'pass_percentage'];

    protected $casts = ['pass_percentage' => 'decimal:2'];

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(AssessmentComponent::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
