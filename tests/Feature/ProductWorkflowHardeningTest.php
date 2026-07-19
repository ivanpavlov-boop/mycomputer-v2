<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_allowed_transition_updates_state_visibility_and_metadata(): void
    {
        $workflow = app(ProductWorkflowService::class);
        $editor = $this->user(User::ROLE_PRODUCT_EDITOR);
        $manager = $this->user(User::ROLE_CATALOG_MANAGER);

        $draft = Product::factory()->manualDraft()->create();
        $draft = $workflow->submitForReview($draft, $editor);

        $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $draft->workflow_status);
        $this->assertSame($editor->id, $draft->submitted_by);
        $this->assertNotNull($draft->submitted_at);
        $this->assertFalse((bool) $draft->active);
        $this->assertSame('hidden', $draft->product_status);
        $this->assertNull($draft->published_at);

        $returned = $workflow->requestChanges($draft, $manager, 'Добавете точен модел и проверете описанието.');

        $this->assertSame(Product::WORKFLOW_CHANGES_REQUESTED, $returned->workflow_status);
        $this->assertSame($manager->id, $returned->returned_by);
        $this->assertNotNull($returned->returned_at);
        $this->assertSame('Добавете точен модел и проверете описанието.', $returned->review_notes);
        $this->assertFalse($returned->isPubliclyVisible());

        $resubmitted = $workflow->submitForReview($returned, $editor);

        $this->assertSame(Product::WORKFLOW_PENDING_REVIEW, $resubmitted->workflow_status);
        $this->assertSame($editor->id, $resubmitted->submitted_by);
        $this->assertSame($manager->id, $resubmitted->returned_by);
        $this->assertSame('Добавете точен модел и проверете описанието.', $resubmitted->review_notes);

        $approved = $workflow->approve($resubmitted, $manager);

        $this->assertSame(Product::WORKFLOW_APPROVED, $approved->workflow_status);
        $this->assertSame($manager->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertFalse((bool) $approved->active);
        $this->assertSame('hidden', $approved->product_status);
        $this->assertFalse($approved->isPubliclyVisible());

        $published = $workflow->publish($approved, $manager);

        $this->assertSame(Product::WORKFLOW_PUBLISHED, $published->workflow_status);
        $this->assertSame($manager->id, $published->published_by);
        $this->assertNotNull($published->published_at);
        $this->assertTrue((bool) $published->active);
        $this->assertSame('active', $published->product_status);
        $this->assertTrue($published->isPubliclyVisible());

        $publishedAt = $published->published_at?->toISOString();
        $hidden = $workflow->hide($published, $manager);

        $this->assertSame(Product::WORKFLOW_APPROVED, $hidden->workflow_status);
        $this->assertFalse((bool) $hidden->active);
        $this->assertSame('hidden', $hidden->product_status);
        $this->assertSame($publishedAt, $hidden->published_at?->toISOString());
        $this->assertSame($manager->id, $hidden->published_by);
        $this->assertFalse($hidden->isPubliclyVisible());

        $approvedReturned = $workflow->requestChanges($hidden, $manager, 'Нова корекция след скриване.');
        $this->assertSame(Product::WORKFLOW_CHANGES_REQUESTED, $approvedReturned->workflow_status);

        $publishedReturned = $workflow->requestChanges(
            Product::factory()->supplierPublished()->create(),
            $manager,
            'Корекция на вече публикуван продукт.',
        );
        $this->assertSame(Product::WORKFLOW_CHANGES_REQUESTED, $publishedReturned->workflow_status);
        $this->assertFalse($publishedReturned->isPubliclyVisible());
    }

    public function test_forbidden_transitions_fail_explicitly_without_mutating_any_workflow_field(): void
    {
        $workflow = app(ProductWorkflowService::class);
        $manager = $this->user(User::ROLE_CATALOG_MANAGER);

        $cases = [
            [Product::WORKFLOW_DRAFT, ProductWorkflowService::ACTION_APPROVE],
            [Product::WORKFLOW_DRAFT, ProductWorkflowService::ACTION_PUBLISH],
            [Product::WORKFLOW_DRAFT, ProductWorkflowService::ACTION_REQUEST_CHANGES],
            [Product::WORKFLOW_PENDING_REVIEW, ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW],
            [Product::WORKFLOW_PENDING_REVIEW, ProductWorkflowService::ACTION_PUBLISH],
            [Product::WORKFLOW_CHANGES_REQUESTED, ProductWorkflowService::ACTION_APPROVE],
            [Product::WORKFLOW_CHANGES_REQUESTED, ProductWorkflowService::ACTION_PUBLISH],
            [Product::WORKFLOW_APPROVED, ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW],
            [Product::WORKFLOW_APPROVED, ProductWorkflowService::ACTION_HIDE],
            [Product::WORKFLOW_PUBLISHED, ProductWorkflowService::ACTION_APPROVE],
            [Product::WORKFLOW_PUBLISHED, ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW],
        ];

        foreach ($cases as [$state, $action]) {
            $product = $this->productInState($state);
            $before = $this->workflowSnapshot($product);

            try {
                $workflow->transition($product, $action, $manager, 'Задължителна бележка.');
                $this->fail("Transition {$state} via {$action} must fail.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('workflow_status', $exception->errors());
            }

            $this->assertSame($before, $this->workflowSnapshot($product));
        }
    }

    public function test_role_matrix_and_inactive_deleted_or_non_admin_users_are_enforced_server_side(): void
    {
        $workflow = app(ProductWorkflowService::class);
        $products = [
            ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW => $this->productInState(Product::WORKFLOW_DRAFT),
            ProductWorkflowService::ACTION_REQUEST_CHANGES => $this->productInState(Product::WORKFLOW_PENDING_REVIEW),
            ProductWorkflowService::ACTION_APPROVE => $this->productInState(Product::WORKFLOW_PENDING_REVIEW),
            ProductWorkflowService::ACTION_PUBLISH => $this->productInState(Product::WORKFLOW_APPROVED),
            ProductWorkflowService::ACTION_HIDE => $this->productInState(Product::WORKFLOW_PUBLISHED),
        ];

        foreach ([User::ROLE_SUPER_ADMIN, User::ROLE_CATALOG_MANAGER] as $role) {
            $user = $this->user($role);

            foreach ($products as $action => $product) {
                $this->assertTrue($workflow->can($product, $action, $user), "{$role} should perform {$action}.");
            }
        }

        foreach ([User::ROLE_PRODUCT_EDITOR, User::ROLE_PRODUCT_DATA_ENTRY] as $role) {
            $user = $this->user($role);

            $this->assertTrue($workflow->can($products[ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW], ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW, $user));

            foreach ([ProductWorkflowService::ACTION_REQUEST_CHANGES, ProductWorkflowService::ACTION_APPROVE, ProductWorkflowService::ACTION_PUBLISH, ProductWorkflowService::ACTION_HIDE] as $action) {
                $this->assertFalse($workflow->can($products[$action], $action, $user), "{$role} must not perform {$action}.");
            }
        }

        foreach ([
            User::ROLE_PRICING_MANAGER,
            User::ROLE_INVENTORY_MANAGER,
            User::ROLE_SEO_MARKETING,
            User::ROLE_ORDER_MANAGER,
            User::ROLE_VIEWER_AUDITOR,
            null,
        ] as $role) {
            $user = $this->user($role);

            foreach ($products as $action => $product) {
                $this->assertFalse($workflow->can($product, $action, $user), ($role ?? 'non-admin')." must not perform {$action}.");
            }
        }

        $inactive = $this->user(User::ROLE_SUPER_ADMIN, active: false);
        $deleted = $this->user(User::ROLE_SUPER_ADMIN);
        $deleted->delete();

        foreach ([$inactive, $deleted] as $blockedUser) {
            foreach ($products as $action => $product) {
                $this->assertFalse($workflow->can($product, $action, $blockedUser));
            }
        }

        $approved = $products[ProductWorkflowService::ACTION_PUBLISH];
        $before = $this->workflowSnapshot($approved);

        try {
            $workflow->publish($approved, $this->user(User::ROLE_PRICING_MANAGER));
            $this->fail('Unauthorized publish must throw an authorization exception.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->workflowSnapshot($approved));
        }
    }

    public function test_required_correction_note_and_publishability_failures_are_atomic(): void
    {
        $workflow = app(ProductWorkflowService::class);
        $manager = $this->user(User::ROLE_CATALOG_MANAGER);
        $pending = $this->productInState(Product::WORKFLOW_PENDING_REVIEW);
        $beforeReturn = $this->workflowSnapshot($pending);

        try {
            $workflow->requestChanges($pending, $manager, '   ');
            $this->fail('A correction note is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_notes', $exception->errors());
        }

        $this->assertSame($beforeReturn, $this->workflowSnapshot($pending));

        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $approved = $this->productInState(Product::WORKFLOW_APPROVED, ['category_id' => $inactiveCategory->id]);
        $beforePublish = $this->workflowSnapshot($approved);

        try {
            $workflow->publish($approved, $manager);
            $this->fail('An inactive category must block publication.');
        } catch (ValidationException $exception) {
            $this->assertSame('Изберете активна категория преди публикуване.', $exception->errors()['category_id'][0]);
        }

        $this->assertSame($beforePublish, $this->workflowSnapshot($approved));

        $inactiveCategory->update(['is_active' => true]);
        $published = $workflow->publish($approved->fresh(), $manager);

        $this->assertTrue($published->isPubliclyVisible());
    }

    public function test_each_publishability_requirement_fails_with_a_specific_bulgarian_error(): void
    {
        $workflow = app(ProductWorkflowService::class);
        $manager = $this->user(User::ROLE_CATALOG_MANAGER);
        $cases = [
            ['name', '', 'Името на продукта е задължително за публикуване.'],
            ['sku', '', 'SKU е задължително за публикуване.'],
            ['slug', '', 'Slug е задължителен за публикуване.'],
            ['category_id', null, 'Изберете активна категория преди публикуване.'],
        ];

        foreach ($cases as [$field, $value, $message]) {
            $product = $this->productInState(Product::WORKFLOW_APPROVED, [$field => $value]);
            $before = $this->workflowSnapshot($product);

            try {
                $workflow->publish($product, $manager);
                $this->fail("The {$field} publishability requirement must block publication.");
            } catch (ValidationException $exception) {
                $this->assertSame($message, $exception->errors()[$field][0]);
            }

            $this->assertSame($before, $this->workflowSnapshot($product));
        }
    }

    public function test_locked_transition_rejects_a_stale_state_instead_of_overwriting_a_newer_transition(): void
    {
        $workflow = app(ProductWorkflowService::class);
        $manager = $this->user(User::ROLE_CATALOG_MANAGER);
        $product = $this->productInState(Product::WORKFLOW_PENDING_REVIEW);
        $firstApproverView = Product::query()->findOrFail($product->id);
        $secondApproverView = Product::query()->findOrFail($product->id);

        $approved = $workflow->approve($firstApproverView, $manager);
        $this->assertSame(Product::WORKFLOW_APPROVED, $approved->workflow_status);

        try {
            $workflow->approve($secondApproverView, $manager);
            $this->fail('A stale approval must fail explicitly.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('променен от друг потребител', $exception->errors()['workflow_status'][0]);
        }

        $this->assertSame(Product::WORKFLOW_APPROVED, $product->fresh()->workflow_status);
        $this->assertSame($manager->id, $product->fresh()->approved_by);
    }

    public function test_soft_deleted_products_cannot_transition_and_restore_is_always_non_public(): void
    {
        $workflow = app(ProductWorkflowService::class);
        $manager = $this->user(User::ROLE_CATALOG_MANAGER);
        $product = $this->productInState(Product::WORKFLOW_PUBLISHED, ['published_by' => $manager->id]);
        $publishedAt = $product->published_at?->toISOString();

        $product->delete();

        try {
            $workflow->hide($product, $manager);
            $this->fail('A soft-deleted product must not transition.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('workflow_status', $exception->errors());
        }

        $this->assertFalse(Product::published()->whereKey($product)->exists());

        $product->restore();
        $product->refresh();

        $this->assertSame(Product::WORKFLOW_APPROVED, $product->workflow_status);
        $this->assertSame('hidden', $product->product_status);
        $this->assertFalse((bool) $product->active);
        $this->assertSame($publishedAt, $product->published_at?->toISOString());
        $this->assertSame($manager->id, $product->published_by);
        $this->assertFalse($product->isPubliclyVisible());
        $this->assertFalse(Product::published()->whereKey($product)->exists());
    }

    private function productInState(string $state, array $overrides = []): Product
    {
        $public = $state === Product::WORKFLOW_PUBLISHED;

        return Product::factory()->create(array_merge([
            'workflow_status' => $state,
            'product_status' => $state === Product::WORKFLOW_DRAFT ? 'draft' : ($public ? 'active' : 'hidden'),
            'active' => $public,
            'published_at' => $public ? now()->subMinute() : null,
        ], $overrides));
    }

    private function user(?string $role, bool $active = true): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    /** @return array<string, mixed> */
    private function workflowSnapshot(Product $product): array
    {
        $product = Product::withTrashed()->findOrFail($product->id);

        return collect([
            'workflow_status',
            'product_status',
            'active',
            'submitted_by',
            'submitted_at',
            'approved_by',
            'approved_at',
            'published_by',
            'published_at',
            'returned_by',
            'returned_at',
            'review_notes',
        ])->mapWithKeys(fn (string $field): array => [$field => $product->getRawOriginal($field)])->all();
    }
}
