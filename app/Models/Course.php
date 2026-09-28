<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    protected $fillable = ['programme_id', 'code', 'title', 'credits'];

    protected $casts = ['credits' => 'integer'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }
}
