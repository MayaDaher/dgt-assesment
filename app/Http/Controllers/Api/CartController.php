<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Http\Responses\ApiResponse;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return $this->cartResponse($request);
    }

    public function store(AddCartItemRequest $request): JsonResponse
    {
        $item = $this->cart->addItem($request->user(), $request->productId(), $request->quantity());

        return $item->wasRecentlyCreated
            ? $this->cartResponse($request, 'Item added to cart.', 201)
            : $this->cartResponse($request, 'Item quantity increased.');
    }

    public function update(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        $this->cart->updateItem($request->user(), $item, $request->quantity());

        return $this->cartResponse($request, 'Cart item updated.');
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->cart->removeItem($request->user(), $item);

        return $this->cartResponse($request, 'Item removed from cart.');
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cart->clear($request->user());

        return $this->cartResponse($request, 'Cart cleared.');
    }

    private function cartResponse(Request $request, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success(
            new CartResource($this->cart->summary($request->user())),
            $message,
            $status,
        );
    }
}
