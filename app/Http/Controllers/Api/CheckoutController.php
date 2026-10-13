<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly CartService $carts,
    ) {}

    /**
     * Prefill for the checkout form. Same shape as POST /checkout's `shipping`
     * object, copied from the customer's most recent order snapshot.
     */
    public function lastShipping(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'shipping' => Order::latestShippingFor($request->user()),
        ]);
    }

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $result = $this->checkout->place(
            cart: $this->carts->resolve($request, createIfMissing: false),
            shipping: $request->shipping(),
            user: $request->user(),
            expectedTotal: $request->expectedTotal(),
            idempotencyKey: $request->idempotencyKey(),
        );

        $order = new OrderResource($result->order->loadMissing(['items', 'user', 'statusChanges']));

        return $result->replayed
            ? ApiResponse::success($order, 'Order already placed.')
            : ApiResponse::created($order, 'Order placed.');
    }
}
