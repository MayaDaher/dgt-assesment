<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Requests\Promotions\PromotionCodeRequest;
use App\Http\Resources\CartResource;
use App\Http\Responses\ApiResponse;
use App\Models\Cart;
use App\Services\Cart\CartService;
use App\Support\CartToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return $this->cartResponse($this->cart->resolve($request));
    }

    public function store(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->cart->resolve($request);
        $item = $this->cart->addItem($cart, $request->productId(), $request->quantity());

        return $item->wasRecentlyCreated
            ? $this->cartResponse($cart, 'Item added to cart.', 201)
            : $this->cartResponse($cart, 'Item quantity increased.');
    }

    public function update(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        $cart = $this->cart->resolve($request, createIfMissing: false);
        $this->cart->updateItem($cart, $item, $request->quantity());

        return $this->cartResponse($cart, 'Cart item updated.');
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $cart = $this->cart->resolve($request, createIfMissing: false);
        $this->cart->removeItem($cart, $item);

        return $this->cartResponse($cart, 'Item removed from cart.');
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cart->resolve($request);
        $this->cart->clear($cart);

        return $this->cartResponse($cart, 'Cart cleared.');
    }

    public function applyPromotion(PromotionCodeRequest $request): JsonResponse
    {
        $cart = $this->cart->resolve($request);
        $this->cart->applyPromotion($cart, $request->code());

        return $this->cartResponse($cart, 'Promotion code applied.');
    }

    public function removePromotion(Request $request): JsonResponse
    {
        $cart = $this->cart->resolve($request);
        $this->cart->removePromotion($cart);

        return $this->cartResponse($cart, 'Promotion code removed.');
    }

    private function cartResponse(Cart $cart, ?string $message = null, int $status = 200): JsonResponse
    {
        $response = ApiResponse::success(
            new CartResource($this->cart->summary($cart)),
            $message,
            $status,
        );

        if ($cart->token !== null) {
            $response->headers->set(CartToken::header(), $cart->token);
        }

        return $response;
    }
}
