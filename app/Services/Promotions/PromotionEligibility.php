<?php

namespace App\Services\Promotions;

use App\Enums\PromotionError;
use App\Exceptions\Promotion\PromotionException;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Money;

/**
 * Decides whether a customer may use a promotion for a given cart subtotal.
 * Rules are checked in a fixed order so the customer always gets the most relevant reason.
 */
class PromotionEligibility
{
    public function __construct(private readonly DiscountCalculator $calculator) {}

    /**
     * @throws PromotionException
     */
    public function findByCode(string $code): Promotion
    {
        return Promotion::query()->where('code', Promotion::normalizeCode($code))->first()
            ?? throw new PromotionException(PromotionError::Invalid);
    }

    /**
     * Returns the discount in cents when the promotion can be used.
     *
     * @throws PromotionException
     */
    public function check(Promotion $promotion, User $user, int $subtotalCents): int
    {
        if ($promotion->trashed()) {
            throw new PromotionException(PromotionError::Invalid);
        }

        if (! $promotion->is_active) {
            throw new PromotionException(PromotionError::Inactive);
        }

        if (! $promotion->hasStarted()) {
            throw new PromotionException(PromotionError::NotStarted, [
                'starts_at' => $promotion->starts_at?->toIso8601String(),
            ]);
        }

        if ($promotion->hasEnded()) {
            throw new PromotionException(PromotionError::Expired, [
                'ended_at' => $promotion->ends_at?->toIso8601String(),
            ]);
        }

        if ($promotion->hasReachedUsageLimit()) {
            throw new PromotionException(PromotionError::UsageLimitReached);
        }

        if ($promotion->per_customer_limit !== null) {
            $used = $promotion->usageCountFor($user);

            if ($used >= $promotion->per_customer_limit) {
                throw new PromotionException(PromotionError::CustomerLimitReached, [
                    'per_customer_limit' => $promotion->per_customer_limit,
                    'times_used' => $used,
                ]);
            }
        }

        if ($promotion->min_cart_amount !== null) {
            $minimum = Money::toCents($promotion->min_cart_amount);

            if ($subtotalCents < $minimum) {
                throw new PromotionException(
                    PromotionError::MinimumNotMet,
                    [
                        'min_cart_amount' => Money::format($minimum),
                        'cart_subtotal' => Money::format($subtotalCents),
                        'amount_remaining' => Money::format($minimum - $subtotalCents),
                    ],
                    'Add '.Money::format($minimum - $subtotalCents).' more to your cart to use this promotion code (minimum '.Money::format($minimum).').',
                );
            }
        }

        return $this->calculator->discountFor($promotion, $subtotalCents);
    }
}
