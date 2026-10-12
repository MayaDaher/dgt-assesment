<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Orders\CancelOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly CancelOrderService $canceller) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $orders = $request->user()
            ->orders()
            ->with('items')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return ApiResponse::success(OrderResource::collection($orders));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success(new OrderResource($order->loadMissing('items')));
    }

    public function cancel(Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $result = $this->canceller->cancel($order);

        return ApiResponse::success(
            new OrderResource($result->order->loadMissing('items')),
            $result->replayed ? 'Order already cancelled.' : 'Order cancelled.',
        );
    }
}
