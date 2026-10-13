<?php

namespace App\Http\Resources;

use App\Models\PromotionAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PromotionAudit
 */
class PromotionAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promotion_id' => $this->promotion_id,
            'promotion_code' => $this->promotion_code,
            'field' => $this->field->value,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'source' => $this->source->value,
            'actor' => $this->when(
                $this->relationLoaded('user'),
                fn () => $this->user === null ? null : new UserResource($this->user),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
