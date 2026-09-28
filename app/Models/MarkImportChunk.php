<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarkImportChunk extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'skip_rows' => 'array',
        'byte_offset' => 'integer',
        'record_count' => 'integer',
        'first_row_number' => 'integer',
    ];
}
