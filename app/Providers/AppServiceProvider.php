<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\OrderPaymentStatusChanged;
use App\Listeners\QueueOrderCancellationErpSync;
use App\Listeners\QueueOrderErpSync;
use App\Listeners\QueuePaymentErpSync;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiRecommendationLog;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ConversionLog;
use App\Models\CsvExportJob;
use App\Models\CsvImportJob;
use App\Models\Customer;
use App\Models\ErpCustomerMapping;
use App\Models\ErpDocument;
use App\Models\ErpProductMapping;
use App\Models\ErpProvider;
use App\Models\ErpSyncJob;
use App\Models\FailedImport;
use App\Models\FeedExport;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\MarketingEvent;
use App\Models\Order;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\PcCompatibilityRule;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCompareItem;
use App\Models\ProductCompareList;
use App\Models\ProductDiscountRule;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductReviewReport;
use App\Models\ProductReviewVote;
use App\Models\Redirect;
use App\Models\SeoMetadata;
use App\Models\SeoPage;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Models\XmlMappingTemplate;
use App\Notifications\AdminPasswordResetNotification;
use App\Policies\AiPolicy;
use App\Policies\BlogPolicy;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\ImportPolicy;
use App\Policies\MarketingPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PagePolicy;
use App\Policies\PcBuilderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RolePolicy;
use App\Policies\SupplierFeedPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserPolicy;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\Providers\BrevoProvider;
use App\Services\Email\Providers\KlaviyoProvider;
use App\Services\Email\Providers\LogEmailProvider;
use App\Services\Email\Providers\MailchimpProvider;
use App\Services\Erp\Contracts\ErpProviderInterface;
use App\Services\Erp\ErpService;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Services\Search\MeilisearchSearchService;
use App\Support\Api\ApiCache;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            FilamentResetPasswordNotification::class,
            fn ($app, array $parameters): AdminPasswordResetNotification => new AdminPasswordResetNotification($parameters['token']),
        );

        $this->app->bind(SearchServiceInterface::class, MeilisearchSearchService::class);
        $this->app->bind(AiProviderInterface::class, MockAiProvider::class);
        $this->app->bind(EmailProviderInterface::class, fn () => match (config('email-marketing.provider')) {
            'brevo' => new BrevoProvider,
            'mailchimp' => new MailchimpProvider,
            'klaviyo' => new KlaviyoProvider,
            default => new LogEmailProvider,
        });
        $this->app->bind(ErpProviderInterface::class, fn () => app(ErpService::class)->provider(app(ErpService::class)->activeProvider()));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('active_eloquent', fn ($app, array $config): ActiveUserProvider => new ActiveUserProvider(
            $app['hash'],
            $config['model'],
        ));

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(AttributeGroup::class, ProductPolicy::class);
        Gate::policy(AttributeValue::class, ProductPolicy::class);
        Gate::policy(ProductAttribute::class, ProductPolicy::class);
        Gate::policy(ProductImage::class, ProductPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(AiConversation::class, AiPolicy::class);
        Gate::policy(AiMessage::class, AiPolicy::class);
        Gate::policy(AiRecommendationLog::class, AiPolicy::class);
        Gate::policy(PcBuild::class, PcBuilderPolicy::class);
        Gate::policy(PcBuildItem::class, PcBuilderPolicy::class);
        Gate::policy(PcCompatibilityRule::class, PcBuilderPolicy::class);
        Gate::policy(MarketingEvent::class, MarketingPolicy::class);
        Gate::policy(FeedExport::class, MarketingPolicy::class);
        Gate::policy(ConversionLog::class, MarketingPolicy::class);
        Gate::policy(BlogCategory::class, BlogPolicy::class);
        Gate::policy(BlogPost::class, BlogPolicy::class);
        Gate::policy(BlogTag::class, BlogPolicy::class);
        Gate::policy(SeoPage::class, PagePolicy::class);
        Gate::policy(Redirect::class, PagePolicy::class);
        Gate::policy(SeoMetadata::class, PagePolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(ErpProvider::class, MarketingPolicy::class);
        Gate::policy(ErpSyncJob::class, MarketingPolicy::class);
        Gate::policy(ErpDocument::class, MarketingPolicy::class);
        Gate::policy(ErpProductMapping::class, MarketingPolicy::class);
        Gate::policy(ErpCustomerMapping::class, MarketingPolicy::class);
        Gate::policy(Wishlist::class, CustomerPolicy::class);
        Gate::policy(WishlistItem::class, CustomerPolicy::class);
        Gate::policy(ProductCompareList::class, CustomerPolicy::class);
        Gate::policy(ProductCompareItem::class, CustomerPolicy::class);
        Gate::policy(ProductReview::class, ProductPolicy::class);
        Gate::policy(ProductReviewVote::class, ProductPolicy::class);
        Gate::policy(ProductReviewReport::class, ProductPolicy::class);
        Gate::policy(PricingRule::class, ProductPolicy::class);
        Gate::policy(ProductDiscountRule::class, ProductPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(SupplierProduct::class, SupplierPolicy::class);
        Gate::policy(SupplierFeed::class, SupplierFeedPolicy::class);
        Gate::policy(ImportJob::class, ImportPolicy::class);
        Gate::policy(ImportHistory::class, ImportPolicy::class);
        Gate::policy(FailedImport::class, ImportPolicy::class);
        Gate::policy(XmlMappingTemplate::class, ImportPolicy::class);
        Gate::policy(CsvImportJob::class, ImportPolicy::class);
        Gate::policy(CsvExportJob::class, ImportPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, RolePolicy::class);

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('reviews', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai', function (Request $request): Limit {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('marketing', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('newsletter', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('coupon', function (Request $request): Limit {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        Event::listen(OrderCreated::class, QueueOrderErpSync::class);
        Event::listen(OrderPaymentStatusChanged::class, QueuePaymentErpSync::class);
        Event::listen(OrderCancelled::class, QueueOrderCancellationErpSync::class);

        foreach ([Product::class, Category::class, Brand::class, BlogPost::class, BlogCategory::class, SeoPage::class] as $model) {
            $model::saved(fn () => ApiCache::bump());
            $model::deleted(fn () => ApiCache::bump());
            $model::restored(fn () => ApiCache::bump());
        }
    }
}
