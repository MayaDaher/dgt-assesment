<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $result = $this->checkout->place($request->user(), $request->idempotencyKey());

        $order = new OrderResource($result->order->loadMissing('items'));

        return $result->replayed
            ? ApiResponse::success($order, 'Order already placed.')
            : ApiResponse::created($order, 'Order placed.');
    }
}
