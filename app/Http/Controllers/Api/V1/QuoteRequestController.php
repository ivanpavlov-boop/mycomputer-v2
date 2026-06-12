<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteFileRequest;
use App\Http\Requests\Api\V1\QuoteMessageRequest;
use App\Http\Requests\Api\V1\QuoteRequestStoreRequest;
use App\Http\Requests\Api\V1\QuoteRequestUpdateRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\QuoteRequestResource;
use App\Models\QuoteRequest;
use App\Services\B2B\QuoteRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $quotes = QuoteRequest::query()
            ->with(['items.product', 'company'])
            ->where(fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->orWhereHas('company.users', fn ($companyUsers) => $companyUsers
                    ->where('user_id', $request->user()->id)
                    ->where('status', 'active')))
            ->latest()
            ->paginate((int) min($request->integer('per_page', 15), 50));

        return response()->json([
            'data' => QuoteRequestResource::collection($quotes),
            'meta' => [
                'current_page' => $quotes->currentPage(),
                'last_page' => $quotes->lastPage(),
                'total' => $quotes->total(),
            ],
        ]);
    }

    public function store(QuoteRequestStoreRequest $request, QuoteRequestService $quotes): QuoteRequestResource
    {
        return new QuoteRequestResource($quotes->create($request->user(), $request->validated())->load(['items.product', 'company', 'messages', 'files']));
    }

    public function show(Request $request, QuoteRequest $quote): QuoteRequestResource
    {
        $this->authorizeOwner($request, $quote);

        return new QuoteRequestResource($quote->load(['items.product', 'company', 'messages' => fn ($query) => $query->where('is_internal', false), 'files']));
    }

    public function update(QuoteRequestUpdateRequest $request, QuoteRequest $quote, QuoteRequestService $quotes): QuoteRequestResource
    {
        $this->authorizeOwner($request, $quote);

        return new QuoteRequestResource($quotes->updateCustomerQuote($quote, $request->validated()));
    }

    public function submit(Request $request, QuoteRequest $quote, QuoteRequestService $quotes): QuoteRequestResource
    {
        $this->authorizeOwner($request, $quote);

        return new QuoteRequestResource($quotes->submit($quote));
    }

    public function accept(Request $request, QuoteRequest $quote, QuoteRequestService $quotes): OrderResource
    {
        $this->authorizeOwner($request, $quote);

        return new OrderResource($quotes->accept($quote));
    }

    public function message(QuoteMessageRequest $request, QuoteRequest $quote, QuoteRequestService $quotes): QuoteRequestResource
    {
        $this->authorizeOwner($request, $quote);
        $quotes->addMessage($quote, $request->user(), $request->validated());

        return new QuoteRequestResource($quote->fresh(['items.product', 'messages' => fn ($query) => $query->where('is_internal', false), 'files']));
    }

    public function file(QuoteFileRequest $request, QuoteRequest $quote, QuoteRequestService $quotes): QuoteRequestResource
    {
        $this->authorizeOwner($request, $quote);
        $quotes->addFile($quote, $request->user(), $request->file('file'));

        return new QuoteRequestResource($quote->fresh(['items.product', 'messages' => fn ($query) => $query->where('is_internal', false), 'files']));
    }

    private function authorizeOwner(Request $request, QuoteRequest $quote): void
    {
        abort_unless($quote->isOwnedBy($request->user()), 404);
    }
}
