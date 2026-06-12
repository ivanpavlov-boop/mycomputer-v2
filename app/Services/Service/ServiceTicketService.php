<?php

namespace App\Services\Service;

use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\B2B\B2BCompanyService;
use App\Services\Email\EmailMarketingService;
use App\Services\Erp\ErpService;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceTicketService
{
    public function __construct(
        private readonly ServiceTicketNumberService $numbers,
        private readonly B2BCompanyService $companies,
        private readonly ErpService $erp,
        private readonly EmailMarketingService $emails,
        private readonly MarketingEventService $events,
    ) {}

    public function create(User $user, array $data): ServiceTicket
    {
        return DB::transaction(function () use ($user, $data): ServiceTicket {
            $order = isset($data['order_id']) ? $this->ownedOrder($user, (int) $data['order_id']) : null;
            $product = isset($data['product_id']) ? Product::query()->findOrFail($data['product_id']) : null;

            if ($order && $product) {
                abort_unless($order->items()->where('product_id', $product->id)->exists(), 422, 'Product does not belong to the selected order.');
            }

            $purchaseDate = $this->purchaseDate($order, $data['purchased_at'] ?? null);
            $warrantyUntil = $this->warrantyExpiresAt($product, $purchaseDate);
            $company = $this->companies->companyForUser($user);

            $ticket = ServiceTicket::query()->create([
                'ticket_number' => $this->numbers->generate(),
                'user_id' => $user->id,
                'order_id' => $order?->id,
                'product_id' => $product?->id,
                'b2b_company_id' => $company?->id,
                'ticket_type' => $data['ticket_type'],
                'status' => 'new',
                'priority' => $data['ticket_type'] === 'doa_request' ? 'high' : ($data['priority'] ?? 'normal'),
                'subject' => Str::limit(strip_tags((string) $data['subject']), 255, ''),
                'description' => Str::limit(strip_tags((string) $data['description']), 5000, ''),
                'serial_number' => $data['serial_number'] ?? null,
                'purchased_at' => $purchaseDate,
                'warranty_expires_at' => $warrantyUntil,
            ]);

            $this->message($ticket, $user, ['message' => $ticket->description], false);
            $this->erpEvent($ticket, 'service_ticket_created');
            $this->emails->queue($user->email, 'ticket_created', ['ticket' => $ticket]);
            $this->events->log('ticket_created', 'internal', ['ticket_id' => $ticket->id, 'ticket_type' => $ticket->ticket_type], $user);

            return $ticket->fresh(['product', 'order', 'company', 'publicMessages.user', 'files']);
        });
    }

    public function message(ServiceTicket $ticket, User $user, array $data, bool $internal = false): ServiceTicket
    {
        $ticket->messages()->create([
            'user_id' => $internal ? null : $user->id,
            'admin_id' => $internal ? $user->id : null,
            'message' => Str::limit(strip_tags((string) $data['message']), 5000, ''),
            'internal_note' => $internal,
        ]);

        if (! $internal && $ticket->status === 'awaiting_customer') {
            $ticket->update(['status' => 'awaiting_review']);
        }

        return $ticket->fresh(['product', 'order', 'publicMessages.user', 'files']);
    }

    public function file(ServiceTicket $ticket, User $user, UploadedFile $file): ServiceTicket
    {
        $path = $file->store('service-ticket-files', 'local');

        $ticket->files()->create([
            'uploaded_by' => $user->id,
            'file_path' => $path,
            'file_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize() ?: 0,
        ]);

        return $ticket->fresh(['product', 'order', 'publicMessages.user', 'files']);
    }

    public function updateWorkflow(ServiceTicket $ticket, User $admin, array $data): ServiceTicket
    {
        $previous = $ticket->status;
        $ticket->fill(collect($data)->only([
            'status', 'priority', 'assigned_to', 'diagnosis', 'resolution', 'work_performed',
            'parts_used', 'repair_date', 'refund_amount', 'refund_date',
        ])->all());

        if (in_array($ticket->status, ['completed', 'closed'], true) && ! $ticket->closed_at) {
            $ticket->closed_at = now();
        }

        $ticket->save();

        if (($data['internal_note'] ?? null)) {
            $this->message($ticket, $admin, ['message' => $data['internal_note']], true);
        }

        if (($data['customer_message'] ?? null)) {
            $this->message($ticket, $admin, ['message' => $data['customer_message']], false);
        }

        if ($previous !== $ticket->status) {
            if ($ticket->user?->email) {
                $this->emails->queue($ticket->user->email, $this->emailTypeForStatus($ticket->status), ['ticket' => $ticket]);
            }

            $this->eventsForStatus($ticket, $admin);
        }

        return $ticket->fresh(['product', 'order', 'company', 'assignee', 'messages.user', 'messages.admin', 'files']);
    }

    public function close(ServiceTicket $ticket, User $user): ServiceTicket
    {
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);
        $this->erpEvent($ticket, 'service_ticket_closed');
        $this->events->log('ticket_closed', 'internal', ['ticket_id' => $ticket->id], $user);

        return $ticket->fresh(['product', 'order', 'publicMessages.user', 'files']);
    }

    public function warrantyStatus(ServiceTicket $ticket): array
    {
        $expires = $ticket->warranty_expires_at;

        return [
            'in_warranty' => $expires ? $expires->isFuture() || $expires->isToday() : false,
            'valid_until' => $expires?->toDateString(),
        ];
    }

    private function ownedOrder(User $user, int $orderId): Order
    {
        return Order::query()
            ->where('id', $orderId)
            ->where(fn ($query) => $query
                ->where('user_id', $user->id)
                ->orWhere(fn ($fallback) => $fallback->whereNull('user_id')->where('customer_email', $user->email)))
            ->firstOrFail();
    }

    private function purchaseDate(?Order $order, ?string $fallback): ?string
    {
        return $order?->created_at?->toDateString() ?? $fallback;
    }

    private function warrantyExpiresAt(?Product $product, ?string $purchaseDate): ?string
    {
        if (! $product || ! $purchaseDate || ! $product->warranty_months) {
            return null;
        }

        return Carbon::parse($purchaseDate)->addMonths((int) $product->warranty_months)->toDateString();
    }

    private function emailTypeForStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'ticket_approved',
            'rejected' => 'ticket_rejected',
            'awaiting_customer' => 'awaiting_customer',
            'repaired', 'completed' => 'repair_completed',
            'replaced' => 'replacement_completed',
            'refunded' => 'refund_completed',
            default => 'ticket_status_changed',
        };
    }

    private function eventsForStatus(ServiceTicket $ticket, User $user): void
    {
        if (in_array($ticket->status, ['completed', 'closed'], true)) {
            $this->erpEvent($ticket, 'service_ticket_closed');
            $this->events->log('ticket_closed', 'internal', ['ticket_id' => $ticket->id], $user);
        }

        if ($ticket->status === 'repaired') {
            $this->events->log('repair_completed', 'internal', ['ticket_id' => $ticket->id], $user);
        }

        if ($ticket->status === 'replaced') {
            $this->erpEvent($ticket, 'replacement_issued');
            $this->events->log('replacement_completed', 'internal', ['ticket_id' => $ticket->id], $user);
        }

        if ($ticket->status === 'refunded') {
            $this->erpEvent($ticket, 'refund_issued');
            $this->events->log('refund_completed', 'internal', ['ticket_id' => $ticket->id, 'refund_amount' => $ticket->refund_amount], $user);
        }
    }

    private function erpEvent(ServiceTicket $ticket, string $event): void
    {
        $this->erp->createSyncJob('push', 'service_ticket', $ticket->id, [
            'event' => $event,
            'ticket_number' => $ticket->ticket_number,
            'ticket_type' => $ticket->ticket_type,
            'status' => $ticket->status,
            'order_id' => $ticket->order_id,
            'product_id' => $ticket->product_id,
        ]);
    }
}
