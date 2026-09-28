<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An examination session, e.g. "Semester 1, Nov 2026".
 *
 * The `status` column moves through these steps, in order:
 *
 *   draft -> marks_entry -> marks_locked -> processing -> results_ready -> published
 *
 * Only the moves listed in ALLOWED_MOVES are possible. The one place that
 * changes the status is App\Services\ExaminationLifecycle.
 */
class Examination extends Model
{
    public const DRAFT = 'draft';                 // setting up courses and components

    public const MARKS_ENTRY = 'marks_entry';     // examiners can enter / upload marks

    public const MARKS_LOCKED = 'marks_locked';   // marks frozen, ready to compute results

    public const PROCESSING = 'processing';       // results are being computed in the background

    public const RESULTS_READY = 'results_ready'; // staff can review results

    public const PUBLISHED = 'published';         // students can see results (final)

    /** All statuses in lifecycle order. */
    public const STATUSES = [
        self::DRAFT, self::MARKS_ENTRY, self::MARKS_LOCKED,
        self::PROCESSING, self::RESULTS_READY, self::PUBLISHED,
    ];

    /** For each status: the statuses it is allowed to move to next. */
    public const ALLOWED_MOVES = [
        self::DRAFT => [self::MARKS_ENTRY],
        self::MARKS_ENTRY => [self::MARKS_LOCKED],
        self::MARKS_LOCKED => [self::MARKS_ENTRY, self::PROCESSING],        // unlock, or compute results
        self::PROCESSING => [self::RESULTS_READY, self::MARKS_LOCKED],      // run finished, or run failed
        self::RESULTS_READY => [self::PUBLISHED, self::PROCESSING, self::MARKS_ENTRY], // publish, re-run, or correct marks
        self::PUBLISHED => [],                                             // final
    ];

    protected $fillable = ['code', 'name', 'academic_year'];

    protected $casts = [
        'current_result_run_id' => 'integer',
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::DRAFT,
    ];

    public function examinationCourses(): HasMany
    {
        return $this->hasMany(ExaminationCourse::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function markImports(): HasMany
    {
        return $this->hasMany(MarkImport::class);
    }

    public function resultRuns(): HasMany
    {
        return $this->hasMany(ResultRun::class);
    }

    /** @return list<string> statuses this examination may move to next */
    public function allowedMoves(): array
    {
        return self::ALLOWED_MOVES[$this->status] ?? [];
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, $this->allowedMoves(), true);
    }

    public function acceptsMarks(): bool
    {
        return $this->status === self::MARKS_ENTRY;
    }

    public function acceptsEnrollments(): bool
    {
        return in_array($this->status, [self::DRAFT, self::MARKS_ENTRY], true);
    }

    /** Courses and components can only be changed before marks entry starts. */
    public function isStructureEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function hasResults(): bool
    {
        return in_array($this->status, [self::RESULTS_READY, self::PUBLISHED], true);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }
}
