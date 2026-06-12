<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceTickets\ServiceTicketResource;
use App\Jobs\SendEmailJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\Service\ServiceTicketService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServicePortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_warranty_ticket_with_order_product_and_warranty_status(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $product = Product::factory()->create(['warranty_months' => 24]);
        $order = $this->orderFor($user, $product, now()->subMonths(6));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/service', [
                'ticket_type' => 'warranty_claim',
                'order_id' => $order->id,
                'product_id' => $product->id,
                'subject' => 'Laptop warranty',
                'description' => 'Display problem',
                'serial_number' => 'SN123',
            ])
            ->assertOk()
            ->assertJsonPath('data.ticket_type', 'warranty_claim')
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.warranty.in_warranty', true);

        $this->assertDatabaseHas('service_tickets', ['user_id' => $user->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('erp_sync_jobs', ['entity_type' => 'service_ticket']);
        $this->assertDatabaseHas('marketing_events', ['event_name' => 'ticket_created']);
        Queue::assertPushed(SendEmailJob::class);
    }

    public function test_customer_cannot_access_another_users_ticket(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ticket = ServiceTicket::query()->create($this->ticketPayload($owner));

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/account/service/{$ticket->id}")
            ->assertNotFound();
    }

    public function test_file_upload_validation_and_successful_upload(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $ticket = ServiceTicket::query()->create($this->ticketPayload($user));

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/service/{$ticket->id}/files", [
                'file' => UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload'),
            ])
            ->assertUnprocessable();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/service/{$ticket->id}/files", [
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.files');
    }

    public function test_order_integration_rejects_products_not_in_order(): void
    {
        $user = User::factory()->create();
        $orderedProduct = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        $order = $this->orderFor($user, $orderedProduct);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/service', [
                'ticket_type' => 'return_request',
                'order_id' => $order->id,
                'product_id' => $otherProduct->id,
                'subject' => 'Return',
                'description' => 'Wrong item',
            ])
            ->assertStatus(422);
    }

    public function test_admin_technician_return_and_doa_workflows(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $customer = User::factory()->create();
        $ticket = ServiceTicket::query()->create($this->ticketPayload($customer, ['ticket_type' => 'doa_request']));

        $updated = app(ServiceTicketService::class)->updateWorkflow($ticket, $admin, [
            'status' => 'repaired',
            'assigned_to' => $admin->id,
            'diagnosis' => 'Faulty RAM',
            'work_performed' => 'RAM replaced',
            'parts_used' => [['part' => 'DDR5 RAM', 'quantity' => 1]],
            'repair_date' => now()->toDateString(),
            'internal_note' => 'Technician note',
        ]);

        $this->assertSame('repaired', $updated->status);
        $this->assertDatabaseHas('service_ticket_messages', ['service_ticket_id' => $ticket->id, 'internal_note' => true]);
        $this->assertDatabaseHas('marketing_events', ['event_name' => 'repair_completed']);

        $updated = app(ServiceTicketService::class)->updateWorkflow($updated, $admin, [
            'status' => 'refunded',
            'refund_amount' => 199.99,
            'refund_date' => now()->toDateString(),
        ]);

        $this->assertSame('refunded', $updated->status);
        $this->assertDatabaseHas('erp_sync_jobs', ['entity_type' => 'service_ticket']);
        $this->assertDatabaseHas('marketing_events', ['event_name' => 'refund_completed']);
        Queue::assertPushed(SendEmailJob::class);
    }

    public function test_customer_messages_hide_internal_notes(): void
    {
        $admin = User::factory()->create();
        $customer = User::factory()->create();
        $ticket = ServiceTicket::query()->create($this->ticketPayload($customer));

        app(ServiceTicketService::class)->message($ticket, $customer, ['message' => 'Customer message']);
        app(ServiceTicketService::class)->message($ticket, $admin, ['message' => 'Internal message'], true);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/account/service/{$ticket->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.message', 'Customer message');
    }

    public function test_service_ticket_filament_resource_requires_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $support = User::factory()->create();
        $customer = User::factory()->create();
        $support->assignRole('support');
        $customer->assignRole('customer');

        $this->actingAs($support);
        $this->assertTrue(ServiceTicketResource::canViewAny());

        $this->actingAs($customer);
        $this->assertFalse(ServiceTicketResource::canViewAny());
    }

    private function orderFor(User $user, Product $product, mixed $createdAt = null): Order
    {
        $order = Order::query()->create([
            'order_number' => 'ORD-'.fake()->unique()->numberBetween(1000, 9999),
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'customer_phone' => $user->phone ?: '0888123456',
            'customer_name' => $user->name,
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'paid',
            'shipping_method' => 'address',
            'shipping_status' => 'delivered',
            'status' => 'completed',
            'created_at' => $createdAt ?? now(),
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
        ]);

        return $order;
    }

    private function ticketPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'ticket_number' => 'SRV-TEST-'.fake()->unique()->numberBetween(1000, 9999),
            'user_id' => $user->id,
            'ticket_type' => 'service_request',
            'status' => 'new',
            'priority' => 'normal',
            'subject' => 'Service request',
            'description' => 'Needs service',
        ], $overrides);
    }
}
