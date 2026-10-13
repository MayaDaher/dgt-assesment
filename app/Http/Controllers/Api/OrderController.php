<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ListOrdersRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Orders\CancelOrderService;
use App\Services\Orders\CapturePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly CancelOrderService $canceller,
        private readonly CapturePaymentService $payments,
    ) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $filters = $request->filters();

        $orders = $request->user()
            ->orders()
            ->with('items')
            ->filtered($filters)
            ->latest('id')
            ->paginate($filters->perPage)
            ->withQueryString();

        return ApiResponse::success(OrderResource::collection($orders));
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success(new OrderResource($order->loadMissing(['items', 'user', 'statusChanges'])));
    }

    public function pay(Request $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);

        $result = $this->payments->capture($order, $request->user()->id);

        return ApiResponse::success(
            new OrderResource($result->order->loadMissing(['items', 'user', 'statusChanges'])),
            $result->replayed ? 'Order already paid.' : 'Payment captured.',
        );
    }

    public function cancel(Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $result = $this->canceller->cancel($order);

        return ApiResponse::success(
            new OrderResource($result->order->loadMissing(['items', 'user', 'statusChanges'])),
            $result->replayed ? 'Order already cancelled.' : 'Order cancelled.',
        );
    }
}
