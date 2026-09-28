<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarkImportError extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['errors' => 'array'];
}
