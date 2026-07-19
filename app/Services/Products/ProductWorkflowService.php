<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ProductWorkflowService
{
    public const ACTION_SUBMIT_FOR_REVIEW = 'submit_for_review';

    public const ACTION_REQUEST_CHANGES = 'request_changes';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_PUBLISH = 'publish';

    public const ACTION_HIDE = 'hide';

    /**
     * Fields owned by the workflow or system creation path. Normal Filament
     * create/edit payloads must never be allowed to set them.
     *
     * @var list<string>
     */
    public const PROTECTED_FORM_FIELDS = [
        'source',
        'workflow_status',
        'product_status',
        'active',
        'published_at',
        'created_by',
        'submitted_by',
        'approved_by',
        'published_by',
        'returned_by',
        'submitted_at',
        'approved_at',
        'returned_at',
        'review_notes',
    ];

    /** @var array<string, string> */
    private const TARGET_STATUS = [
        self::ACTION_SUBMIT_FOR_REVIEW => Product::WORKFLOW_PENDING_REVIEW,
        self::ACTION_REQUEST_CHANGES => Product::WORKFLOW_CHANGES_REQUESTED,
        self::ACTION_APPROVE => Product::WORKFLOW_APPROVED,
        self::ACTION_PUBLISH => Product::WORKFLOW_PUBLISHED,
        self::ACTION_HIDE => Product::WORKFLOW_APPROVED,
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_FROM = [
        self::ACTION_SUBMIT_FOR_REVIEW => [
            Product::WORKFLOW_DRAFT,
            Product::WORKFLOW_CHANGES_REQUESTED,
        ],
        self::ACTION_REQUEST_CHANGES => [
            Product::WORKFLOW_PENDING_REVIEW,
            Product::WORKFLOW_APPROVED,
            Product::WORKFLOW_PUBLISHED,
        ],
        self::ACTION_APPROVE => [Product::WORKFLOW_PENDING_REVIEW],
        self::ACTION_PUBLISH => [Product::WORKFLOW_APPROVED],
        self::ACTION_HIDE => [Product::WORKFLOW_PUBLISHED],
    ];

    /** @var array<string, string> */
    private const POLICY_ABILITY = [
        self::ACTION_SUBMIT_FOR_REVIEW => 'submitForReview',
        self::ACTION_REQUEST_CHANGES => 'requestChanges',
        self::ACTION_APPROVE => 'approve',
        self::ACTION_PUBLISH => 'publish',
        self::ACTION_HIDE => 'hide',
    ];

    public function can(Product $product, string $action, ?User $actor): bool
    {
        if (! $actor?->isActiveAdminAccount() || $product->trashed() || ! isset(self::TARGET_STATUS[$action])) {
            return false;
        }

        return in_array($product->workflow_status, self::ALLOWED_FROM[$action], true)
            && Gate::forUser($actor)->allows(self::POLICY_ABILITY[$action], $product);
    }

    public function submitForReview(Product $product, User $actor, ?string $note = null): Product
    {
        return $this->transition($product, self::ACTION_SUBMIT_FOR_REVIEW, $actor, $note);
    }

    public function requestChanges(Product $product, User $actor, string $note): Product
    {
        return $this->transition($product, self::ACTION_REQUEST_CHANGES, $actor, $note);
    }

    public function approve(Product $product, User $actor): Product
    {
        return $this->transition($product, self::ACTION_APPROVE, $actor);
    }

    public function publish(Product $product, User $actor): Product
    {
        return $this->transition($product, self::ACTION_PUBLISH, $actor);
    }

    public function hide(Product $product, User $actor): Product
    {
        return $this->transition($product, self::ACTION_HIDE, $actor);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function transition(Product $product, string $action, User $actor, ?string $note = null): Product
    {
        if (! isset(self::TARGET_STATUS[$action])) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Неподдържано действие за работния процес.',
            ]);
        }

        $expectedStatus = (string) $product->workflow_status;

        return DB::transaction(function () use ($product, $action, $actor, $note, $expectedStatus): Product {
            $lockedProduct = Product::withTrashed()
                ->lockForUpdate()
                ->findOrFail($product->getKey());

            if ($lockedProduct->trashed()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Изтрит продукт не може да променя работния си статус.',
                ]);
            }

            Gate::forUser($actor)->authorize(self::POLICY_ABILITY[$action], $lockedProduct);

            if ($lockedProduct->workflow_status !== $expectedStatus) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Работният статус е променен от друг потребител. Обновете страницата и опитайте отново.',
                ]);
            }

            if (! in_array($lockedProduct->workflow_status, self::ALLOWED_FROM[$action], true)) {
                throw ValidationException::withMessages([
                    'workflow_status' => sprintf(
                        'Преходът от „%s“ към „%s“ не е разрешен.',
                        Product::workflowStatusLabel($lockedProduct->workflow_status),
                        Product::workflowStatusLabel(self::TARGET_STATUS[$action]),
                    ),
                ]);
            }

            $note = is_string($note) ? trim($note) : null;

            if ($action === self::ACTION_REQUEST_CHANGES && blank($note)) {
                throw ValidationException::withMessages([
                    'review_notes' => 'Бележката за корекции е задължителна.',
                ]);
            }

            if ($action === self::ACTION_PUBLISH) {
                $this->validatePublishability($lockedProduct);
            }

            $lockedProduct->forceFill($this->updatesFor($action, $actor, $note))->save();

            return $lockedProduct->refresh();
        });
    }

    /**
     * Preserve the existing, allowlisted remediation command while keeping its
     * workflow mutation inside the same transactional boundary.
     */
    public function moveToReviewForMaintenance(Product $product, string $targetStatus, string $note): Product
    {
        if (! in_array($targetStatus, [Product::WORKFLOW_DRAFT, Product::WORKFLOW_PENDING_REVIEW], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Невалиден статус за ограничената операция по преглед.',
            ]);
        }

        $expectedStatus = (string) $product->workflow_status;

        return DB::transaction(function () use ($product, $targetStatus, $note, $expectedStatus): Product {
            $lockedProduct = Product::withTrashed()
                ->lockForUpdate()
                ->findOrFail($product->getKey());

            if ($lockedProduct->trashed()) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Изтрит продукт не може да бъде преместен за преглед.',
                ]);
            }

            if ($lockedProduct->workflow_status !== $expectedStatus) {
                throw ValidationException::withMessages([
                    'workflow_status' => 'Работният статус е променен от друг потребител. Обновете данните и опитайте отново.',
                ]);
            }

            $lockedProduct->forceFill([
                'workflow_status' => $targetStatus,
                'product_status' => $targetStatus === Product::WORKFLOW_DRAFT ? 'draft' : 'hidden',
                'active' => false,
                'review_notes' => trim($note),
            ])->save();

            return $lockedProduct->refresh();
        });
    }

    /**
     * Eloquent applies these values as part of the restore save. Publication
     * metadata remains historical, but restoration never makes a product public.
     */
    public function prepareForRestore(Product $product): void
    {
        $product->active = false;

        if ($product->workflow_status === Product::WORKFLOW_PUBLISHED) {
            $product->workflow_status = Product::WORKFLOW_APPROVED;
            $product->product_status = 'hidden';

            return;
        }

        if (! array_key_exists((string) $product->workflow_status, Product::workflowStatusOptions())) {
            $product->workflow_status = Product::WORKFLOW_DRAFT;
        }

        $product->product_status = $product->workflow_status === Product::WORKFLOW_DRAFT
            ? 'draft'
            : 'hidden';
    }

    /** @return array<string, mixed> */
    private function updatesFor(string $action, User $actor, ?string $note): array
    {
        $now = now();

        return match ($action) {
            self::ACTION_SUBMIT_FOR_REVIEW => array_filter([
                'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
                'active' => false,
                'product_status' => 'hidden',
                'submitted_by' => $actor->id,
                'submitted_at' => $now,
                'review_notes' => filled($note) ? $note : null,
            ], fn (mixed $value, string $key): bool => $key !== 'review_notes' || $value !== null, ARRAY_FILTER_USE_BOTH),
            self::ACTION_REQUEST_CHANGES => [
                'workflow_status' => Product::WORKFLOW_CHANGES_REQUESTED,
                'active' => false,
                'product_status' => 'hidden',
                'returned_by' => $actor->id,
                'returned_at' => $now,
                'review_notes' => $note,
            ],
            self::ACTION_APPROVE => [
                'workflow_status' => Product::WORKFLOW_APPROVED,
                'active' => false,
                'product_status' => 'hidden',
                'approved_by' => $actor->id,
                'approved_at' => $now,
            ],
            self::ACTION_PUBLISH => [
                'workflow_status' => Product::WORKFLOW_PUBLISHED,
                'active' => true,
                'product_status' => 'active',
                'published_by' => $actor->id,
                'published_at' => $now,
            ],
            self::ACTION_HIDE => [
                'workflow_status' => Product::WORKFLOW_APPROVED,
                'active' => false,
                'product_status' => 'hidden',
            ],
        };
    }

    /** @throws ValidationException */
    private function validatePublishability(Product $product): void
    {
        $errors = [];

        if (blank($product->name)) {
            $errors['name'] = 'Името на продукта е задължително за публикуване.';
        }

        if (blank($product->slug)) {
            $errors['slug'] = 'Slug е задължителен за публикуване.';
        } elseif (Product::withTrashed()->where('slug', $product->slug)->whereKeyNot($product->getKey())->exists()) {
            $errors['slug'] = 'Slug трябва да бъде уникален, за да публикувате продукта.';
        }

        if (blank($product->sku)) {
            $errors['sku'] = 'SKU е задължително за публикуване.';
        }

        if (! $product->category_id || ! Category::query()->whereKey($product->category_id)->where('is_active', true)->exists()) {
            $errors['category_id'] = 'Изберете активна категория преди публикуване.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
