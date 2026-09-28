<?php

namespace App\Http\Resources;

use App\Models\Mark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Mark */
class MarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'assessment_component_id' => $this->assessment_component_id,
            'marks' => $this->marks_obtained,
            'absent' => $this->is_absent,
            'version' => $this->version,
            'source' => $this->source,
            'mark_import_id' => $this->mark_import_id,
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
