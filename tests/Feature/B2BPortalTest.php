<?php

namespace Tests\Feature;

use App\Filament\Resources\B2BCompanies\B2BCompanyResource;
use App\Filament\Resources\QuoteRequests\QuoteRequestResource;
use App\Jobs\SyncOrderToErpJob;
use App\Models\B2BCompany;
use App\Models\Product;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\B2B\B2BCompanyService;
use App\Services\B2B\QuoteRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class B2BPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_user_can_apply_for_b2b_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/b2b/apply', $this->applicationPayload())
            ->assertCreated()
            ->assertJsonPath('data.approval_status', 'pending');

        $this->assertDatabaseHas('b2b_companies', ['vat_number' => 'BG123456789', 'approval_status' => 'pending']);
        $this->assertDatabaseHas('b2b_company_users', ['user_id' => $user->id, 'role' => 'owner']);
    }

    public function test_admin_can_approve_company(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $company = B2BCompany::query()->create([
            'name' => 'Pending Ltd',
            'vat_number' => 'BG111',
            'status' => 'inactive',
            'approval_status' => 'pending',
        ]);

        app(B2BCompanyService::class)->approve($company, $admin);

        $this->assertDatabaseHas('b2b_companies', [
            'id' => $company->id,
            'status' => 'active',
            'approval_status' => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    public function test_user_can_create_and_submit_quote_request(): void
    {
        $user = $this->b2bUser();
        $product = Product::query()->where('active', true)->whereNotNull('published_at')->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/quotes', [
                'notes' => 'Need 10 units',
                'items' => [['product_id' => $product->id, 'quantity' => 10, 'requested_price' => 900]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $quoteId = $response->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/quotes/{$quoteId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_cart_can_be_converted_to_quote_request(): void
    {
        $user = $this->b2bUser();
        $product = Product::query()->where('active', true)->whereNotNull('published_at')->firstOrFail();

        $this->withHeader('X-Cart-Session', 'b2b-cart')
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Cart-Session', 'b2b-cart')
            ->postJson('/api/v1/cart/request-quote', ['notes' => 'Cart quote'])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.source', 'cart');
    }

    public function test_product_can_be_requested_as_quote(): void
    {
        $user = $this->b2bUser();
        $product = Product::query()->where('active', true)->whereNotNull('published_at')->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/products/{$product->slug}/request-quote", ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.source', 'product_page');
    }

    public function test_user_cannot_access_another_company_quote(): void
    {
        $owner = $this->b2bUser('owner@example.com');
        $other = $this->b2bUser('other@example.com');
        $quote = $this->quoteFor($owner);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/account/quotes/{$quote->id}")
            ->assertNotFound();
    }

    public function test_admin_can_set_offered_prices_and_customer_cannot(): void
    {
        $user = $this->b2bUser();
        $quote = $this->quoteFor($user);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/account/quotes/{$quote->id}", ['offered_price' => 1])
            ->assertUnprocessable();

        app(QuoteRequestService::class)->offer($quote, [
            'items' => [['id' => $quote->items()->first()->id, 'offered_price' => 950]],
            'valid_until' => now()->addDays(7)->toDateString(),
        ]);

        $this->assertDatabaseHas('quote_requests', ['id' => $quote->id, 'status' => 'offered']);
        $this->assertDatabaseHas('quote_request_items', ['quote_request_id' => $quote->id, 'offered_price' => 950]);
    }

    public function test_offered_quote_can_be_accepted_and_creates_order_and_erp_sync_job(): void
    {
        Queue::fake();
        $user = $this->b2bUser();
        $quote = $this->quoteFor($user);
        app(QuoteRequestService::class)->offer($quote, [
            'items' => [['id' => $quote->items()->first()->id, 'offered_price' => 950]],
            'valid_until' => now()->addDays(7)->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/quotes/{$quote->id}/accept")
            ->assertCreated()
            ->assertJsonPath('data.quote_request_id', $quote->id);

        $this->assertDatabaseHas('quote_requests', ['id' => $quote->id, 'status' => 'converted']);
        $this->assertDatabaseHas('orders', ['quote_request_id' => $quote->id]);
        $this->assertDatabaseHas('erp_sync_jobs', ['entity_type' => 'order', 'status' => 'pending']);
        Queue::assertPushed(SyncOrderToErpJob::class);
    }

    public function test_expired_quote_cannot_be_accepted(): void
    {
        $user = $this->b2bUser();
        $quote = $this->quoteFor($user);
        app(QuoteRequestService::class)->offer($quote, [
            'items' => [['id' => $quote->items()->first()->id, 'offered_price' => 950]],
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/quotes/{$quote->id}/accept")
            ->assertUnprocessable();
    }

    public function test_permissions_are_enforced_for_filament_resources(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $customer = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin);
        $this->assertTrue(B2BCompanyResource::canViewAny());
        $this->assertTrue(QuoteRequestResource::canViewAny());

        $this->actingAs($customer);
        $this->assertFalse(B2BCompanyResource::canViewAny());
        $this->assertFalse(QuoteRequestResource::canViewAny());
    }

    public function test_file_upload_validation(): void
    {
        Storage::fake('local');
        $user = $this->b2bUser();
        $quote = $this->quoteFor($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/quotes/{$quote->id}/files", [
                'file' => UploadedFile::fake()->create('malware.exe', 10),
            ])
            ->assertUnprocessable();

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/account/quotes/{$quote->id}/files", [
                'file' => UploadedFile::fake()->create('request.pdf', 10, 'application/pdf'),
            ])
            ->assertOk();
    }

    private function b2bUser(string $email = 'b2b@example.com'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'is_active' => true,
        ]);
        $company = B2BCompany::query()->create([
            'name' => 'B2B Ltd',
            'vat_number' => 'BG'.fake()->unique()->numberBetween(100000000, 999999999),
            'email' => $email,
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
        $company->users()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

        return $user;
    }

    private function quoteFor(User $user): QuoteRequest
    {
        $product = Product::query()->where('active', true)->whereNotNull('published_at')->firstOrFail();

        return app(QuoteRequestService::class)->create($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'requested_price' => 1000]],
        ]);
    }

    private function applicationPayload(): array
    {
        return [
            'company_name' => 'My Company Ltd',
            'vat_number' => 'BG123456789',
            'mol' => 'Ivan Petrov',
            'email' => 'office@example.com',
            'phone' => '0888123456',
            'billing_address' => 'Sofia billing',
            'shipping_address' => 'Sofia shipping',
        ];
    }
}
