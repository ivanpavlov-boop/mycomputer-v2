<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_CATALOG_MANAGER = 'catalog_manager';

    public const ROLE_PRODUCT_EDITOR = 'product_editor';

    public const ROLE_PRODUCT_DATA_ENTRY = 'product_data_entry';

    public const ROLE_PRICING_MANAGER = 'pricing_manager';

    public const ROLE_INVENTORY_MANAGER = 'inventory_manager';

    public const ROLE_SEO_MARKETING = 'seo_marketing';

    public const ROLE_ORDER_MANAGER = 'order_manager';

    public const ROLE_VIEWER_AUDITOR = 'viewer_auditor';

    public const ADMIN_ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_CATALOG_MANAGER,
        self::ROLE_PRODUCT_EDITOR,
        self::ROLE_PRODUCT_DATA_ENTRY,
        self::ROLE_PRICING_MANAGER,
        self::ROLE_INVENTORY_MANAGER,
        self::ROLE_SEO_MARKETING,
        self::ROLE_ORDER_MANAGER,
        self::ROLE_VIEWER_AUDITOR,
    ];

    public const ROLE_LABELS = [
        self::ROLE_SUPER_ADMIN => 'Super Admin',
        self::ROLE_CATALOG_MANAGER => 'Catalog Manager',
        self::ROLE_PRODUCT_EDITOR => 'Product Editor',
        self::ROLE_PRODUCT_DATA_ENTRY => 'Product Data Entry',
        self::ROLE_PRICING_MANAGER => 'Pricing Manager',
        self::ROLE_INVENTORY_MANAGER => 'Inventory Manager',
        self::ROLE_SEO_MARKETING => 'SEO / Marketing',
        self::ROLE_ORDER_MANAGER => 'Order Manager',
        self::ROLE_VIEWER_AUDITOR => 'Viewer / Auditor',
    ];

    public const LEGACY_ROLE_MAP = [
        'admin' => self::ROLE_SUPER_ADMIN,
        'manager' => self::ROLE_CATALOG_MANAGER,
        'support' => self::ROLE_ORDER_MANAGER,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'company_name',
        'vat_number',
        'is_active',
        'last_login_at',
        'role',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(LoyaltyAccount::class);
    }

    public function voucherRedemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    public function referralCode(): HasOne
    {
        return $this->hasOne(ReferralCode::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function compareLists(): HasMany
    {
        return $this->hasMany(ProductCompareList::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function blogPosts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'author_id');
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function pcBuilds(): HasMany
    {
        return $this->hasMany(PcBuild::class);
    }

    public function b2bCompanyUsers(): HasMany
    {
        return $this->hasMany(B2BCompanyUser::class, 'user_id');
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }

    public function marketingEvents(): HasMany
    {
        return $this->hasMany(MarketingEvent::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isActiveAdminAccount() && (
            in_array($this->primaryRole(), self::ADMIN_ROLES, true)
            || $this->hasAnyRole(array_keys(self::LEGACY_ROLE_MAP))
        );
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return self::ROLE_LABELS;
    }

    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[$role] ?? 'Unassigned';
    }

    public function primaryRole(): ?string
    {
        if (filled($this->role)) {
            return $this->role;
        }

        foreach (self::LEGACY_ROLE_MAP as $legacyRole => $primaryRole) {
            if ($this->hasRole($legacyRole)) {
                return $primaryRole;
            }
        }

        return null;
    }

    public function hasPrimaryRole(string|array $roles): bool
    {
        return in_array($this->primaryRole(), (array) $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasPrimaryRole(self::ROLE_SUPER_ADMIN);
    }

    public function isActiveAdminAccount(): bool
    {
        return $this->is_active && ! $this->trashed();
    }

    public function canManageUsers(): bool
    {
        return $this->isActiveAdminAccount() && $this->isSuperAdmin();
    }

    public function canManageRoles(): bool
    {
        return $this->isActiveAdminAccount() && $this->isSuperAdmin();
    }

    public function canViewCatalogSync(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
            self::ROLE_VIEWER_AUDITOR,
        ]);
    }

    public function canRunCreateSync(): bool
    {
        return $this->isActiveAdminAccount() && $this->isSuperAdmin();
    }

    public function canRunUpdateSync(): bool
    {
        return $this->isActiveAdminAccount() && $this->isSuperAdmin();
    }

    public function canViewAuditLogs(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_VIEWER_AUDITOR,
        ]);
    }

    public function canManageProducts(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
            self::ROLE_PRODUCT_EDITOR,
            self::ROLE_PRODUCT_DATA_ENTRY,
            self::ROLE_PRICING_MANAGER,
            self::ROLE_INVENTORY_MANAGER,
        ]);
    }

    public function canEditProductContent(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
            self::ROLE_PRODUCT_EDITOR,
            self::ROLE_PRODUCT_DATA_ENTRY,
        ]);
    }

    public function canEditProductPrices(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
            self::ROLE_PRICING_MANAGER,
        ]);
    }

    public function canEditProductStock(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
            self::ROLE_INVENTORY_MANAGER,
        ]);
    }

    public function canEditProductSeo(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
            self::ROLE_PRODUCT_EDITOR,
            self::ROLE_SEO_MARKETING,
        ]);
    }

    public function canApproveProducts(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
        ]);
    }

    public function canPublishProducts(): bool
    {
        return $this->isActiveAdminAccount() && $this->hasPrimaryRole([
            self::ROLE_SUPER_ADMIN,
            self::ROLE_CATALOG_MANAGER,
        ]);
    }
}
