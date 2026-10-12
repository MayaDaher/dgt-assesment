<?php

namespace App\Services\Cart;

use App\Exceptions\Cart\CartNotFoundException;
use App\Exceptions\Cart\EmptyCartException;
use App\Exceptions\Catalog\ProductUnavailableException;
use App\Exceptions\Promotion\PromotionException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\StockReservationService;
use App\Services\Promotions\PromotionEligibility;
use App\Services\Promotions\PromotionPreview;
use App\Support\CartToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cart mutations. Each one locks the cart row so two parallel requests cannot
 * both read the old quantity and overwrite each other.
 *
 * Guests are identified by `X-Cart-Token`; authenticated users by Sanctum.
 * Adding a line reserves that quantity until checkout or TTL expiry.
 */
class CartService
{
    public function __construct(
        private readonly CartCalculator $calculator,
        private readonly PromotionEligibility $eligibility,
        private readonly StockReservationService $reservations,
    ) {}

    /**
     * @throws CartNotFoundException
     */
    public function resolve(Request $request, bool $createIfMissing = true): Cart
    {
        $user = $request->user();
        $token = CartToken::from($request);

        if ($user !== null) {
            $this->claimGuestCart($user, $token);

            return $this->cartFor($user);
        }

        if ($token !== null) {
            return Cart::query()->where('token', $token)->whereNull('user_id')->first()
                ?? throw new CartNotFoundException;
        }

        if (! $createIfMissing) {
            throw new CartNotFoundException;
        }

        return $this->createGuestCart();
    }

    public function cartFor(User $user): Cart
    {
        return Cart::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['token' => null],
        );
    }

    /**
     * Moves a guest cart onto the authenticated user: adopts it when the user
     * has no cart yet, otherwise merges lines and keeps the user's cart.
     */
    public function claimGuestCart(User $user, ?string $token): void
    {
        if ($token === null || $token === '' || ! Str::isUuid($token)) {
            return;
        }

        $guest = Cart::query()->where('token', $token)->whereNull('user_id')->first();

        if ($guest === null) {
            return;
        }

        $existing = $user->cart;

        if ($existing === null) {
            $guest->update([
                'user_id' => $user->id,
                'token' => null,
            ]);

            return;
        }

        $this->mergeGuestCart($existing, $token);
    }

    public function summary(Cart $cart): CartSummary
    {
        $this->reservations->sync($cart);
        $cart->unsetRelation('reservations');

        return $this->calculator->summarize($cart->refresh());
    }

    /**
     * Adds the product or increases the quantity of the existing line.
     */
    public function addItem(Cart $cart, int $productId, int $quantity): CartItem
    {
        return DB::transaction(function () use ($cart, $productId, $quantity) {
            $this->lock($cart);

            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $this->ensurePurchasable($product);

            /** @var CartItem|null $item */
            $item = $cart->items()->where('product_id', $product->id)->first();
            $newQuantity = ($item->quantity ?? 0) + $quantity;

            $this->ensureWithinLimit($newQuantity);
            $this->reservations->reserve($cart, $product, $newQuantity);

            if ($item === null) {
                $item = $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $newQuantity,
                ]);
            } else {
                $item->update(['quantity' => $newQuantity]);
            }

            $cart->touch();

            return $item;
        });
    }

    public function updateItem(Cart $cart, int $itemId, int $quantity): CartItem
    {
        return DB::transaction(function () use ($cart, $itemId, $quantity) {
            $this->lock($cart);

            /** @var CartItem $item */
            $item = $cart->items()->whereKey($itemId)->firstOrFail();

            $product = Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
            $item->setRelation('product', $product);

            $this->ensurePurchasable($product);
            $this->ensureWithinLimit($quantity);
            $this->reservations->reserve($cart, $product, $quantity);

            $item->update(['quantity' => $quantity]);
            $cart->touch();

            return $item;
        });
    }

    public function removeItem(Cart $cart, int $itemId): void
    {
        DB::transaction(function () use ($cart, $itemId) {
            $this->lock($cart);

            /** @var CartItem $item */
            $item = $cart->items()->whereKey($itemId)->firstOrFail();
            $productId = $item->product_id;
            $item->delete();
            $this->reservations->releaseProductId($cart, $productId);

            $cart->touch();
        });
    }

    public function clear(Cart $cart): void
    {
        DB::transaction(function () use ($cart) {
            $this->lock($cart);
            $cart->items()->delete();
            $this->reservations->release($cart);
            $cart->update(['promotion_id' => null]);
        });
    }

    /**
     * Checks a code against the current cart without applying it.
     *
     * @throws EmptyCartException|PromotionException
     */
    public function previewPromotion(Cart $cart, string $code): PromotionPreview
    {
        $this->reservations->sync($cart);
        $summary = $this->calculator->summarize($cart->refresh(), withPromotion: false);

        if ($summary->isEmpty()) {
            throw new EmptyCartException;
        }

        $promotion = $this->eligibility->findByCode($code);
        $discount = $this->eligibility->check($promotion, $cart->user, $summary->lines);

        return new PromotionPreview(
            $promotion,
            $summary->subtotalCents,
            $discount,
            $this->eligibility->eligibleSubtotal($promotion, $summary->lines),
        );
    }

    /**
     * Validates the code and attaches it to the cart (replacing any previous code).
     *
     * @throws EmptyCartException|PromotionException
     */
    public function applyPromotion(Cart $cart, string $code): void
    {
        DB::transaction(function () use ($cart, $code) {
            $this->lock($cart);

            $preview = $this->previewPromotion($cart, $code);

            $cart->update(['promotion_id' => $preview->promotion->id]);
        });
    }

    public function removePromotion(Cart $cart): void
    {
        $cart->update(['promotion_id' => null]);
    }

    private function createGuestCart(): Cart
    {
        return Cart::query()->create([
            'user_id' => null,
            'token' => (string) Str::uuid(),
        ]);
    }

    private function mergeGuestCart(Cart $userCart, string $token): void
    {
        $guest = Cart::query()->where('token', $token)->whereNull('user_id')->first();

        if ($guest === null || $guest->is($userCart)) {
            return;
        }

        DB::transaction(function () use ($userCart, $guest) {
            $ids = [$userCart->id, $guest->id];
            sort($ids);

            foreach ($ids as $id) {
                Cart::query()->whereKey($id)->lockForUpdate()->first();
            }

            $guest->load('items');
            $userCart->load('items');

            $productIds = $guest->items
                ->pluck('product_id')
                ->merge($userCart->items->pluck('product_id'))
                ->unique()
                ->sort()
                ->values();

            $products = $productIds->isEmpty()
                ? collect()
                : Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $this->reservations->release($guest);

            foreach ($guest->items as $guestItem) {
                $product = $products->get($guestItem->product_id);

                if ($product === null || $product->trashed() || ! $product->isActive()) {
                    continue;
                }

                /** @var CartItem|null $existing */
                $existing = $userCart->items->firstWhere('product_id', $guestItem->product_id);
                $desired = min(($existing->quantity ?? 0) + $guestItem->quantity, CartItem::MAX_QUANTITY);
                $available = $this->reservations->availableFor($product, $userCart);
                $quantity = min($desired, $available);

                if ($quantity <= 0) {
                    continue;
                }

                if ($existing === null) {
                    $existing = $userCart->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                    ]);
                    $userCart->setRelation('items', $userCart->items->push($existing));
                } else {
                    $existing->update(['quantity' => $quantity]);
                }

                $this->reservations->reserve($userCart, $product, $quantity);
            }

            if ($userCart->promotion_id === null && $guest->promotion_id !== null) {
                $userCart->update(['promotion_id' => $guest->promotion_id]);
            }

            $guest->delete();
            $userCart->touch();
        });
    }

    private function lock(Cart $cart): void
    {
        Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
    }

    private function ensurePurchasable(Product $product): void
    {
        if ($product->trashed() || ! $product->isActive()) {
            throw new ProductUnavailableException($product);
        }
    }

    private function ensureWithinLimit(int $quantity): void
    {
        if ($quantity > CartItem::MAX_QUANTITY) {
            throw ValidationException::withMessages([
                'quantity' => 'A cart line cannot exceed '.CartItem::MAX_QUANTITY.' units.',
            ]);
        }
    }
}
