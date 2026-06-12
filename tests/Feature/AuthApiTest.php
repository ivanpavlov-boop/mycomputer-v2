<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_registration_creates_customer_with_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'email' => 'ivan@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'ivan@example.com')
            ->assertJsonPath('data.user.roles.0', 'customer')
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password1')]);
        $user->assignRole('customer');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_reset(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password1')]);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'Newpass1',
            'password_confirmation' => 'Newpass1',
        ])->assertOk();

        $this->assertTrue(Hash::check('Newpass1', $user->refresh()->password));
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/account')->assertUnauthorized();
        $this->getJson('/api/v1/auth/addresses')->assertUnauthorized();
    }

    public function test_password_update_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password1')]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'Wrongpass1',
                'password' => 'Newpass1',
                'password_confirmation' => 'Newpass1',
            ])
            ->assertUnprocessable();
    }

    public function test_profile_update(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/profile', [
                'first_name' => 'Maria',
                'last_name' => 'Ivanova',
                'phone' => '0888123456',
                'newsletter_subscribed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Maria')
            ->assertJsonPath('data.profile.newsletter_subscribed', true);
    }

    public function test_address_management(): void
    {
        $user = User::factory()->create();

        $addressId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/addresses', $this->addressPayload())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/auth/addresses/{$addressId}", array_merge($this->addressPayload(), ['city' => 'Plovdiv']))
            ->assertOk()
            ->assertJsonPath('data.city', 'Plovdiv');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/auth/addresses/{$addressId}")
            ->assertOk();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $addressId]);
    }

    public function test_cannot_manage_another_users_address(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $address = CustomerAddress::query()->create(array_merge($this->addressPayload(), [
            'user_id' => $owner->id,
        ]));

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/v1/auth/addresses/{$address->id}", $this->addressPayload())
            ->assertNotFound();

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/auth/addresses/{$address->id}")
            ->assertNotFound();
    }

    public function test_role_assignment_and_permission_checks(): void
    {
        $admin = User::factory()->create();
        $manager = User::factory()->create();

        $admin->assignRole('admin');
        $manager->assignRole('manager');

        $this->assertTrue($admin->can('manage users'));
        $this->assertTrue($manager->can('manage orders'));
        $this->assertFalse($manager->can('manage roles'));
    }

    public function test_inactive_user_login_is_blocked(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password1'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertUnprocessable();
    }

    public function test_order_history_returns_only_own_orders(): void
    {
        $user = User::factory()->create(['email' => 'client@example.com']);
        $ownOrder = Order::query()->create($this->orderPayload('client@example.com'));
        $otherOrder = Order::query()->create($this->orderPayload('other@example.com'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/account/orders')
            ->assertOk()
            ->assertJsonFragment(['order_number' => $ownOrder->order_number])
            ->assertJsonMissing(['order_number' => $otherOrder->order_number]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/account/orders/{$ownOrder->id}")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/account/orders/{$otherOrder->id}")
            ->assertNotFound();
    }

    public function test_user_id_order_ownership_takes_priority_over_email_fallback(): void
    {
        $user = User::factory()->create(['email' => 'client@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);
        $ownedById = Order::query()->create(array_merge($this->orderPayload('changed@example.com'), ['user_id' => $user->id]));
        $historicalGuest = Order::query()->create($this->orderPayload('client@example.com'));
        $otherUserOrder = Order::query()->create(array_merge($this->orderPayload('client@example.com'), ['user_id' => $other->id]));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/account/orders')
            ->assertOk()
            ->assertJsonFragment(['order_number' => $ownedById->order_number])
            ->assertJsonFragment(['order_number' => $historicalGuest->order_number])
            ->assertJsonMissing(['order_number' => $otherUserOrder->order_number]);
    }

    private function addressPayload(): array
    {
        return [
            'type' => 'shipping',
            'first_name' => 'Ivan',
            'last_name' => 'Petrov',
            'phone' => '0888123456',
            'country' => 'Bulgaria',
            'city' => 'Sofia',
            'postcode' => '1000',
            'address_line_1' => 'bul. Bulgaria 1',
            'is_default' => true,
        ];
    }

    private function orderPayload(string $email): array
    {
        return [
            'order_number' => 'MC'.fake()->unique()->numerify('######'),
            'customer_email' => $email,
            'customer_phone' => '0888123456',
            'customer_name' => 'Ivan Petrov',
            'billing_address' => 'Sofia',
            'shipping_address' => 'Sofia',
            'subtotal' => 100,
            'shipping_price' => 5,
            'discount_total' => 0,
            'grand_total' => 105,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'shipping_method' => 'office',
            'shipping_status' => 'pending',
            'status' => 'pending',
        ];
    }
}
