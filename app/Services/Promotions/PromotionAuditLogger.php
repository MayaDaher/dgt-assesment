<?php

namespace App\Services\Promotions;

use App\Enums\ProductAuditSource;
use App\Enums\PromotionAuditField;
use App\Models\Promotion;
use App\Models\PromotionAudit;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Writes an immutable row for each admin/system change to a promotion's commercial fields.
 *
 * Checkout increments `used_count` with a query, which does not fire Eloquent `updated`.
 */
class PromotionAuditLogger
{
    public function record(Promotion $promotion): void
    {
        $actor = auth()->user();

        foreach (PromotionAuditField::cases() as $field) {
            $change = $this->change($promotion, $field);

            if ($change === null) {
                continue;
            }

            PromotionAudit::query()->create([
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'user_id' => $actor?->id,
                'field' => $field,
                'old_value' => $change[0],
                'new_value' => $change[1],
                'source' => $actor instanceof User && $actor->isAdmin()
                    ? ProductAuditSource::Admin
                    : ProductAuditSource::System,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function change(Promotion $promotion, PromotionAuditField $field): ?array
    {
        if (! $promotion->wasChanged($field->value)) {
            return null;
        }

        $old = $this->normalize($field, $promotion->getOriginal($field->value));
        $new = $this->normalize($field, $promotion->getAttribute($field->value));

        if ($old === $new) {
            return null;
        }

        return [$old, $new];
    }

    private function normalize(PromotionAuditField $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($field) {
            PromotionAuditField::Value,
            PromotionAuditField::MinCartAmount,
            PromotionAuditField::MaxDiscountAmount => number_format((float) $value, 2, '.', ''),
            PromotionAuditField::IsActive => ((int) (bool) $value) === 1 ? '1' : '0',
            PromotionAuditField::UsageLimit,
            PromotionAuditField::PerCustomerLimit => (string) (int) $value,
            PromotionAuditField::StartsAt,
            PromotionAuditField::EndsAt => $value instanceof Carbon
                ? $value->toIso8601String()
                : Carbon::parse((string) $value)->toIso8601String(),
            PromotionAuditField::Type,
            PromotionAuditField::Scope => is_object($value) && isset($value->value)
                ? (string) $value->value
                : (string) $value,
            PromotionAuditField::Code => (string) $value,
        };
    }
}
