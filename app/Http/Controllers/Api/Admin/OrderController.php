<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminOrdersRequest;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Queries\Orders\OrderFilters;
use App\Services\Orders\AdvanceOrderStatusService;
use Illuminate\Database\Eloquent\Builder;
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
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->latest('id')
            ->paginate($filters->perPage)
            ->withQueryString();

        return ApiResponse::success(OrderResource::collection($orders));
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success(new OrderResource($order->loadMissing(['items', 'user'])));
    }

    public function update(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $updated = $this->advancer->advance($order, $request->status());

        return ApiResponse::success(new OrderResource($updated), 'Order status updated.');
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function applyFilters(Builder $query, OrderFilters $filters): void
    {
        $query
            ->when($filters->status !== null, fn (Builder $q) => $q->where('status', $filters->status))
            ->when($filters->userId !== null, fn (Builder $q) => $q->where('user_id', $filters->userId))
            ->when($filters->from !== null, fn (Builder $q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('created_at', '<=', $filters->to));

        $term = trim((string) $filters->search);

        if ($term === '') {
            return;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $like = "%{$escaped}%";

        $query->where(function (Builder $inner) use ($like) {
            $inner
                ->whereRaw("order_number LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('user', function (Builder $user) use ($like) {
                    $user
                        ->whereRaw("email LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]);
                });
        });
    }
}
