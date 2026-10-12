<?php

namespace App\Http\Resources;

use App\Models\ProductAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductAudit
 */
class ProductAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_sku' => $this->product_sku,
            'product_name' => $this->product_name,
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
