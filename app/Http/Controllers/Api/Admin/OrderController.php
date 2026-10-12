<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminOrdersRequest;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Orders\AdvanceOrderStatusService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private readonly AdvanceOrderStatusService $advancer) {}

    public function index(ListAdminOrdersRequest $request): JsonResponse
    {
        $this->authorize('viewAll', Order::class);

        $filters = $request->filters();

        $orders = Order::query()
            ->with(['items', 'user'])
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

    public function update(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $updated = $this->advancer->advance($order, $request->status(), $request->user()?->id);

        return ApiResponse::success(new OrderResource($updated), 'Order status updated.');
    }
}
