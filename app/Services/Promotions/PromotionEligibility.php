<?php

namespace App\Services\Promotions;

use App\Enums\PromotionError;
use App\Enums\PromotionScope;
use App\Exceptions\Promotion\PromotionException;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Cart\CartLine;
use App\Support\Money;

/**
 * Decides whether a customer may use a promotion for a given cart.
 * Rules are checked in a fixed order so the customer always gets the most relevant reason.
 *
 * Product- and category-scoped codes are priced only against matching purchasable lines.
 * `min_cart_amount` is measured on that eligible subtotal.
 */
class PromotionEligibility
{
    public function __construct(private readonly DiscountCalculator $calculator) {}

    /**
     * @throws PromotionException
     */
    public function findByCode(string $code): Promotion
    {
        return Promotion::query()
            ->with(['products', 'categories.children'])
            ->where('code', Promotion::normalizeCode($code))
            ->first()
            ?? throw new PromotionException(PromotionError::Invalid);
    }

    /**
     * Returns the discount in cents when the promotion can be used.
     * Guests skip the per-customer usage limit until they sign in and check out.
     *
     * @param  list<CartLine>  $lines
     *
     * @throws PromotionException
     */
    public function check(Promotion $promotion, ?User $user, array $lines): int
    {
        $promotion->loadMissing(['products', 'categories.children']);

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

        if ($promotion->per_customer_limit !== null && $user !== null) {
            $used = $promotion->usageCountFor($user);

            if ($used >= $promotion->per_customer_limit) {
                throw new PromotionException(PromotionError::CustomerLimitReached, [
                    'per_customer_limit' => $promotion->per_customer_limit,
                    'times_used' => $used,
                ]);
            }
        }

        $eligibleCents = $this->eligibleSubtotal($promotion, $lines);

        if ($promotion->scope->isRestricted() && $eligibleCents <= 0) {
            throw new PromotionException(PromotionError::NotApplicable, [
                'scope' => $promotion->scope->value,
                'product_ids' => $promotion->scope === PromotionScope::Products
                    ? $promotion->products->pluck('id')->values()->all()
                    : [],
                'category_ids' => $promotion->scope === PromotionScope::Categories
                    ? $promotion->categories->pluck('id')->values()->all()
                    : [],
            ]);
        }

        if ($promotion->min_cart_amount !== null) {
            $minimum = Money::toCents($promotion->min_cart_amount);

            if ($eligibleCents < $minimum) {
                throw new PromotionException(
                    PromotionError::MinimumNotMet,
                    [
                        'min_cart_amount' => Money::format($minimum),
                        'cart_subtotal' => Money::format($eligibleCents),
                        'eligible_subtotal' => Money::format($eligibleCents),
                        'amount_remaining' => Money::format($minimum - $eligibleCents),
                    ],
                    'Add '.Money::format($minimum - $eligibleCents).' more to your cart to use this promotion code (minimum '.Money::format($minimum).').',
                );
            }
        }

        return $this->calculator->discountFor($promotion, $eligibleCents);
    }

    /**
     * @param  list<CartLine>  $lines
     */
    public function eligibleSubtotal(Promotion $promotion, array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            if ($line->isPurchasable() && $promotion->appliesTo($line->product)) {
                $total += $line->lineTotalCents;
            }
        }

        return $total;
    }
}
