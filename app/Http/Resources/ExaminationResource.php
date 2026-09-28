<?php

namespace App\Http\Resources;

use App\Models\Examination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Examination */
class ExaminationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'academic_year' => $this->academic_year,
            'status' => $this->status,
            'allowed_transitions' => $this->allowedMoves(),
            'current_result_run_id' => $this->current_result_run_id,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
