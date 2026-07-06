<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SupplierCategoryMapping extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IGNORED = 'ignored';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    protected $fillable = [
        'supplier_id',
        'supplier_key',
        'supplier_name',
        'supplier_category_name',
        'supplier_category_slug',
        'supplier_category_path',
        'supplier_category_external_id',
        'supplier_category_hash',
        'canonical_product_family_id',
        'target_category_id',
        'status',
        'confidence',
        'match_reason',
        'notes',
        'reviewed_at',
        'reviewed_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SupplierCategoryMapping $mapping): void {
            if (blank($mapping->supplier_category_slug) && filled($mapping->supplier_category_name)) {
                $mapping->supplier_category_slug = Str::slug((string) $mapping->supplier_category_name);
            }

            if (
                blank($mapping->supplier_category_hash)
                || $mapping->isDirty([
                    'supplier_id',
                    'supplier_key',
                    'supplier_name',
                    'supplier_category_name',
                    'supplier_category_slug',
                    'supplier_category_path',
                    'supplier_category_external_id',
                ])
            ) {
                $mapping->supplier_category_hash = self::makeHash([
                    'supplier_id' => $mapping->supplier_id,
                    'supplier_key' => $mapping->supplier_key,
                    'supplier_name' => $mapping->supplier_name,
                    'supplier_category_name' => $mapping->supplier_category_name,
                    'supplier_category_slug' => $mapping->supplier_category_slug,
                    'supplier_category_path' => $mapping->supplier_category_path,
                    'supplier_category_external_id' => $mapping->supplier_category_external_id,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public static function makeHash(array $identity): string
    {
        $supplierIdentity = filled($identity['supplier_id'] ?? null)
            ? 'supplier_id:'.(string) $identity['supplier_id']
            : 'supplier:'.Str::lower((string) ($identity['supplier_key'] ?? $identity['supplier_name'] ?? 'unknown'));

        $categoryIdentity = collect([
            'name' => $identity['supplier_category_name'] ?? null,
            'slug' => $identity['supplier_category_slug'] ?? null,
            'path' => $identity['supplier_category_path'] ?? null,
            'external_id' => $identity['supplier_category_external_id'] ?? null,
        ])
            ->map(fn (mixed $value): string => Str::lower(trim((string) $value)))
            ->implode('|');

        return sha1($supplierIdentity.'|'.$categoryIdentity);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function canonicalProductFamily(): BelongsTo
    {
        return $this->belongsTo(CanonicalProductFamily::class);
    }

    public function targetCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'target_category_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
