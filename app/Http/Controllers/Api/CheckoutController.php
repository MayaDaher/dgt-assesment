<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly CartService $carts,
    ) {}

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $result = $this->checkout->place(
            cart: $this->carts->resolve($request, createIfMissing: false),
            shipping: $request->shipping(),
            user: $request->user(),
            idempotencyKey: $request->idempotencyKey(),
        );

        $order = new OrderResource($result->order->loadMissing(['items', 'user', 'statusChanges']));

        return $result->replayed
            ? ApiResponse::success($order, 'Order already placed.')
            : ApiResponse::created($order, 'Order placed.');
    }
}
