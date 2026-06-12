<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ServiceTicketFileRequest;
use App\Http\Requests\Api\V1\ServiceTicketMessageRequest;
use App\Http\Requests\Api\V1\ServiceTicketStoreRequest;
use App\Http\Resources\ServiceTicketResource;
use App\Models\Order;
use App\Models\ServiceTicket;
use App\Services\Service\ServiceTicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceTicketController extends Controller
{
    public function __construct(private readonly ServiceTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $tickets = ServiceTicket::query()
            ->with(['product', 'order', 'files'])
            ->where(fn (Builder $query) => $query
                ->where('user_id', $request->user()->id)
                ->orWhereHas('company.users', fn ($companyUsers) => $companyUsers
                    ->where('user_id', $request->user()->id)
                    ->where('status', 'active')))
            ->latest()
            ->paginate((int) min($request->integer('per_page', 15), 50));

        return response()->json([
            'data' => ServiceTicketResource::collection($tickets),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function store(ServiceTicketStoreRequest $request): ServiceTicketResource
    {
        return new ServiceTicketResource($this->tickets->create($request->user(), $request->validated()));
    }

    public function show(Request $request, ServiceTicket $ticket): ServiceTicketResource
    {
        $this->authorizeOwner($request, $ticket);

        return new ServiceTicketResource($ticket->load(['product', 'order', 'publicMessages.user', 'files']));
    }

    public function message(ServiceTicketMessageRequest $request, ServiceTicket $ticket): ServiceTicketResource
    {
        $this->authorizeOwner($request, $ticket);

        return new ServiceTicketResource($this->tickets->message($ticket, $request->user(), $request->validated()));
    }

    public function file(ServiceTicketFileRequest $request, ServiceTicket $ticket): ServiceTicketResource
    {
        $this->authorizeOwner($request, $ticket);

        return new ServiceTicketResource($this->tickets->file($ticket, $request->user(), $request->file('file')));
    }

    public function close(Request $request, ServiceTicket $ticket): ServiceTicketResource
    {
        $this->authorizeOwner($request, $ticket);

        return new ServiceTicketResource($this->tickets->close($ticket, $request->user()));
    }

    public function orderProducts(Request $request, Order $order): JsonResponse
    {
        abort_unless(
            $order->user_id === $request->user()->id ||
            ($order->user_id === null && $order->customer_email === $request->user()->email),
            404
        );

        return response()->json([
            'data' => $order->items()->with('product')->get()->map(fn ($item): array => [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'sku' => $item->sku,
                'purchased_at' => $order->created_at?->toDateString(),
                'warranty_months' => $item->product?->warranty_months,
            ])->values(),
        ]);
    }

    private function authorizeOwner(Request $request, ServiceTicket $ticket): void
    {
        abort_unless($ticket->isOwnedBy($request->user()), 404);
    }
}
