<?php

namespace App\Services\Cart;

use App\Exceptions\Catalog\ProductUnavailableException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cart mutations. Each one locks the customer's cart row, so two parallel requests from the
 * same customer cannot both read the old quantity and overwrite each other.
 *
 * Stock is checked here to give early feedback, but nothing is reserved: stock is checked
 * again (with row locks) at checkout, which is the only place inventory is decremented.
 */
class CartService
{
    public function __construct(private readonly CartCalculator $calculator) {}

    public function summary(User $user): CartSummary
    {
        return $this->calculator->summarize($this->cartFor($user));
    }

    public function cartFor(User $user): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Adds the product or increases the quantity of the existing line.
     */
    public function addItem(User $user, int $productId, int $quantity): CartItem
    {
        $cart = $this->cartFor($user);

        return DB::transaction(function () use ($cart, $productId, $quantity) {
            $this->lock($cart);

            $product = Product::query()->findOrFail($productId);
            $this->ensurePurchasable($product);

            /** @var CartItem|null $item */
            $item = $cart->items()->where('product_id', $product->id)->first();
            $newQuantity = ($item->quantity ?? 0) + $quantity;

            $this->ensureWithinLimit($newQuantity);
            $this->ensureInStock($product, $newQuantity);

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

    public function updateItem(User $user, int $itemId, int $quantity): CartItem
    {
        $cart = $this->existingCartOrFail($user);

        return DB::transaction(function () use ($cart, $itemId, $quantity) {
            $this->lock($cart);

            /** @var CartItem $item */
            $item = $cart->items()->with('product')->whereKey($itemId)->firstOrFail();

            $this->ensurePurchasable($item->product);
            $this->ensureWithinLimit($quantity);
            $this->ensureInStock($item->product, $quantity);

            $item->update(['quantity' => $quantity]);
            $cart->touch();

            return $item;
        });
    }

    public function removeItem(User $user, int $itemId): void
    {
        $cart = $this->existingCartOrFail($user);

        $cart->items()->whereKey($itemId)->firstOrFail()->delete();
        $cart->touch();
    }

    public function clear(User $user): void
    {
        $this->cartFor($user)->items()->delete();
    }

    private function existingCartOrFail(User $user): Cart
    {
        return $user->cart ?? throw (new ModelNotFoundException)->setModel(CartItem::class);
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

    private function ensureInStock(Product $product, int $quantity): void
    {
        if ($quantity > $product->stock_quantity) {
            throw new InsufficientStockException($product, $quantity, $product->stock_quantity);
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
