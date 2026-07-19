<?php

namespace App\Services\B2B;

use App\Jobs\SyncOrderToErpJob;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Email\EmailMarketingService;
use App\Services\Erp\ErpService;
use App\Services\Orders\OrderNumberService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuoteRequestService
{
    public function __construct(
        private readonly QuoteNumberService $quoteNumber,
        private readonly OrderNumberService $orderNumber,
        private readonly CartService $cartService,
        private readonly B2BCompanyService $companies,
        private readonly ErpService $erp,
        private readonly EmailMarketingService $emailMarketing,
    ) {}

    public function create(User $user, array $data, string $source = 'b2b_portal'): QuoteRequest
    {
        return DB::transaction(function () use ($user, $data, $source): QuoteRequest {
            $company = $this->companies->companyForUser($user);
            $quote = QuoteRequest::query()->create([
                'user_id' => $user->id,
                'b2b_company_id' => $company?->id,
                'quote_number' => $this->quoteNumber->generate(),
                'customer_name' => $data['customer_name'] ?? trim($user->first_name.' '.$user->last_name) ?: $user->name,
                'customer_email' => $data['customer_email'] ?? $user->email,
                'customer_phone' => $data['customer_phone'] ?? $user->phone,
                'company_name' => $data['company_name'] ?? $company?->name ?? $user->company_name,
                'vat_number' => $data['vat_number'] ?? $company?->vat_number ?? $user->vat_number,
                'status' => $data['status'] ?? 'draft',
                'source' => $source,
                'notes' => Str::limit(strip_tags((string) ($data['notes'] ?? '')), 2000, ''),
            ]);

            foreach ($data['items'] ?? [] as $item) {
                $this->addItem($quote, $item, false);
            }

            $this->recalculate($quote);

            return $quote->fresh(['items.product', 'company', 'messages', 'files']);
        });
    }

    public function createFromCart(User $user, Cart $cart, array $data = []): QuoteRequest
    {
        $cart = $this->cartService->recalculate($cart);
        abort_unless($cart->user_id === null || (int) $cart->user_id === (int) $user->id, 404);

        $items = $cart->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'requested_price' => null,
            'notes' => null,
        ])->values()->all();

        return $this->submit($this->create($user, $data + ['items' => $items], 'cart'));
    }

    public function createFromProduct(User $user, Product $product, array $data): QuoteRequest
    {
        abort_unless($product->isPubliclyVisible(), 422, 'Product is not available.');

        return $this->submit($this->create($user, $data + [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => (int) ($data['quantity'] ?? 1),
                'requested_price' => $data['requested_price'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]],
        ], 'product_page'));
    }

    public function addItem(QuoteRequest $quote, array $data, bool $recalculate = true): void
    {
        $product = Product::query()->published()->find($data['product_id'] ?? null);
        abort_unless($product, 422, 'Product is not available.');

        $quote->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => max(1, min((int) ($data['quantity'] ?? 1), 999)),
            'requested_price' => isset($data['requested_price']) ? max(0, (float) $data['requested_price']) : null,
            'notes' => Str::limit(strip_tags((string) ($data['notes'] ?? '')), 1000, ''),
        ]);

        if ($recalculate) {
            $this->recalculate($quote);
        }
    }

    public function updateCustomerQuote(QuoteRequest $quote, array $data): QuoteRequest
    {
        abort_unless(in_array($quote->status, ['draft', 'submitted'], true), 422, 'Quote can no longer be edited by customer.');

        $quote->update([
            'notes' => array_key_exists('notes', $data) ? Str::limit(strip_tags((string) $data['notes']), 2000, '') : $quote->notes,
            'customer_phone' => $data['customer_phone'] ?? $quote->customer_phone,
        ]);

        return $quote->fresh(['items.product', 'messages', 'files']);
    }

    public function submit(QuoteRequest $quote): QuoteRequest
    {
        abort_if($quote->items()->count() === 0, 422, 'Quote request must contain at least one product.');

        $quote->update([
            'status' => 'submitted',
            'submitted_at' => $quote->submitted_at ?: now(),
        ]);

        $this->emailMarketing->queue($quote->customer_email, 'quote_submitted', ['quote' => $quote]);

        return $quote->fresh(['items.product', 'messages', 'files']);
    }

    public function offer(QuoteRequest $quote, array $data): QuoteRequest
    {
        return DB::transaction(function () use ($quote, $data): QuoteRequest {
            foreach ($data['items'] ?? [] as $itemData) {
                $item = $quote->items()->findOrFail($itemData['id']);
                $offeredPrice = max(0, (float) $itemData['offered_price']);
                $item->update([
                    'offered_price' => $offeredPrice,
                    'line_total' => $offeredPrice * $item->quantity,
                ]);
            }

            $this->recalculate($quote);
            $quote->update([
                'status' => 'offered',
                'valid_until' => $data['valid_until'] ?? now()->addDays(7)->toDateString(),
                'internal_notes' => $data['internal_notes'] ?? $quote->internal_notes,
                'approved_at' => now(),
            ]);

            $this->emailMarketing->queue($quote->customer_email, 'quote_offer_sent', ['quote' => $quote]);

            return $quote->fresh(['items.product', 'messages', 'files']);
        });
    }

    public function accept(QuoteRequest $quote): Order
    {
        abort_unless($quote->status === 'offered', 422, 'Only offered quotes can be accepted.');
        abort_if($quote->valid_until && $quote->valid_until->isPast(), 422, 'Quote offer has expired.');

        return DB::transaction(function () use ($quote): Order {
            $quote->update(['status' => 'accepted']);
            $order = $this->convertToOrder($quote);
            $quote->update([
                'status' => 'converted',
                'converted_order_id' => $order->id,
            ]);
            $this->emailMarketing->queue($quote->customer_email, 'quote_accepted', ['quote' => $quote, 'order' => $order]);

            return $order;
        });
    }

    public function convertToOrder(QuoteRequest $quote): Order
    {
        $quote->loadMissing(['items.product', 'user', 'company']);
        $subtotal = (float) $quote->items->sum(fn ($item): float => (float) ($item->line_total ?? (($item->offered_price ?? $item->requested_price ?? $item->product?->price ?? 0) * $item->quantity)));

        $customer = Customer::query()->updateOrCreate(
            ['email' => $quote->customer_email],
            [
                'first_name' => Str::before($quote->customer_name, ' ') ?: $quote->customer_name,
                'last_name' => Str::after($quote->customer_name, ' ') ?: '',
                'phone' => $quote->customer_phone,
                'company_name' => $quote->company_name,
                'vat_number' => $quote->vat_number,
                'billing_address' => $quote->company?->billing_address ?? '',
                'shipping_address' => $quote->company?->shipping_address ?? '',
            ],
        );

        $order = Order::query()->create([
            'order_number' => $this->orderNumber->generate(),
            'customer_id' => $customer->id,
            'user_id' => $quote->user_id,
            'b2b_company_id' => $quote->b2b_company_id,
            'quote_request_id' => $quote->id,
            'customer_email' => $quote->customer_email,
            'customer_phone' => $quote->customer_phone ?: '',
            'customer_name' => $quote->customer_name,
            'company_name' => $quote->company_name,
            'vat_number' => $quote->vat_number,
            'billing_address' => $quote->company?->billing_address ?? '',
            'shipping_address' => $quote->company?->shipping_address ?? '',
            'subtotal' => $subtotal,
            'shipping_price' => 0,
            'discount_total' => (float) ($quote->discount_total ?? 0),
            'grand_total' => max(0, $subtotal - (float) ($quote->discount_total ?? 0)),
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'shipping_method' => 'manual',
            'shipping_status' => 'pending',
            'status' => 'pending',
            'notes' => 'Converted from quote '.$quote->quote_number,
        ]);

        foreach ($quote->items as $item) {
            $unitPrice = (float) ($item->offered_price ?? $item->requested_price ?? $item->product?->price ?? 0);
            $order->items()->create([
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $item->quantity,
            ]);
        }

        $syncJob = $this->erp->createSyncJob('push', 'order', $order->id, $this->erp->orderPayload($order));
        SyncOrderToErpJob::dispatch($syncJob->id);

        return $order->load('items');
    }

    public function addMessage(QuoteRequest $quote, User $user, array $data, string $senderType = 'customer'): void
    {
        $quote->messages()->create([
            'user_id' => $user->id,
            'sender_type' => $senderType,
            'message' => Str::limit(strip_tags((string) $data['message']), 3000, ''),
            'is_internal' => (bool) ($data['is_internal'] ?? false),
        ]);
    }

    public function addFile(QuoteRequest $quote, User $user, UploadedFile $file): void
    {
        $path = $file->store('quote-files', 'local');

        $quote->files()->create([
            'uploaded_by' => $user->id,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
        ]);
    }

    private function recalculate(QuoteRequest $quote): void
    {
        $quote->items()->get()->each(function ($item): void {
            if ($item->offered_price !== null) {
                $item->update(['line_total' => (float) $item->offered_price * $item->quantity]);
            }
        });

        $subtotal = (float) $quote->items()->sum('line_total');
        $quote->update([
            'subtotal' => $subtotal ?: null,
            'grand_total' => $subtotal ? max(0, $subtotal - (float) ($quote->discount_total ?? 0)) : null,
        ]);
    }
}
