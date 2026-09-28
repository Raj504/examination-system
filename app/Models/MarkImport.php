<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded marks CSV file and its processing progress.
 *
 * Status flow: pending -> splitting -> processing -> completed | completed_with_errors | failed
 */
class MarkImport extends Model
{
    use HasUuids;

    public const PENDING = 'pending';                              // uploaded, waiting for a worker

    public const SPLITTING = 'splitting';                          // file is being scanned and split into chunks

    public const PROCESSING = 'processing';                        // chunks are being written

    public const COMPLETED = 'completed';                          // every row was applied

    public const COMPLETED_WITH_ERRORS = 'completed_with_errors';  // valid rows applied, some rows rejected

    public const FAILED = 'failed';                                // file invalid, or some chunks could not be processed

    /** Statuses meaning "still running". */
    public const ACTIVE_STATUSES = [self::PENDING, self::SPLITTING, self::PROCESSING];

    // The id is chosen by the service (it names the stored file), so it is fillable.
    protected $guarded = [];

    protected $casts = [
        'column_map' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(MarkImportChunk::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(MarkImportError::class);
    }
}
