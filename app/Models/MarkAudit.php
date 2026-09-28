<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarkAudit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'old_marks' => 'decimal:2',
        'new_marks' => 'decimal:2',
        'old_is_absent' => 'boolean',
        'new_is_absent' => 'boolean',
    ];
}
