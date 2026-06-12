<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountReviewController;
use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Admin\ErpController;
use App\Http\Controllers\Api\V1\Admin\SupplierImportController;
use App\Http\Controllers\Api\V1\AiChatController;
use App\Http\Controllers\Api\V1\AiSearchController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\B2BController;
use App\Http\Controllers\Api\V1\BlogCategoryController;
use App\Http\Controllers\Api\V1\BlogController;
use App\Http\Controllers\Api\V1\BlogTagController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\BundleController;
use App\Http\Controllers\Api\V1\CartBundleController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CartQuoteController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CompareController;
use App\Http\Controllers\Api\V1\CompareListController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FilterController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\MarketingController;
use App\Http\Controllers\Api\V1\NavigationController;
use App\Http\Controllers\Api\V1\NewsletterController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PcBuilderController;
use App\Http\Controllers\Api\V1\ProductAlertController;
use App\Http\Controllers\Api\V1\ProductAlternativeController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductQuoteController;
use App\Http\Controllers\Api\V1\ProductReviewController;
use App\Http\Controllers\Api\V1\QuoteRequestController;
use App\Http\Controllers\Api\V1\ReviewReportController;
use App\Http\Controllers\Api\V1\ReviewVoteController;
use App\Http\Controllers\Api\V1\RewardController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SeoController;
use App\Http\Controllers\Api\V1\SeoPageController;
use App\Http\Controllers\Api\V1\ServiceTicketController;
use App\Http\Controllers\Api\V1\ShippingController;
use App\Http\Controllers\Api\V1\SitemapController;
use App\Http\Controllers\Api\V1\WishlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class);
    Route::get('home', [HomeController::class, 'index']);
    Route::get('content/homepage', [ContentController::class, 'homepage']);
    Route::get('content/pages/{slug}', [ContentController::class, 'show']);
    Route::get('content/templates', [ContentController::class, 'templates']);
    Route::get('content/block-types', [ContentController::class, 'blockTypes']);
    Route::get('sitemap.xml', [SitemapController::class, 'sitemap']);
    Route::get('robots.txt', [SitemapController::class, 'robots']);

    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
        Route::patch('auth/password', [AuthController::class, 'updatePassword']);

        Route::get('auth/addresses', [AddressController::class, 'index']);
        Route::post('auth/addresses', [AddressController::class, 'store']);
        Route::patch('auth/addresses/{address}', [AddressController::class, 'update']);
        Route::delete('auth/addresses/{address}', [AddressController::class, 'destroy']);

        Route::get('account', [AccountController::class, 'show']);
        Route::get('account/loyalty', LoyaltyController::class);
        Route::get('account/orders', [AccountController::class, 'orders']);
        Route::get('account/orders/{order}', [AccountController::class, 'order']);
        Route::get('account/reviews', AccountReviewController::class);
        Route::get('account/service', [ServiceTicketController::class, 'index']);
        Route::post('account/service', [ServiceTicketController::class, 'store']);
        Route::get('account/service/order-products/{order}', [ServiceTicketController::class, 'orderProducts']);
        Route::get('account/service/{ticket}', [ServiceTicketController::class, 'show']);
        Route::post('account/service/{ticket}/messages', [ServiceTicketController::class, 'message']);
        Route::post('account/service/{ticket}/files', [ServiceTicketController::class, 'file']);
        Route::post('account/service/{ticket}/close', [ServiceTicketController::class, 'close']);

        Route::get('b2b/status', [B2BController::class, 'status']);
        Route::post('b2b/apply', [B2BController::class, 'apply'])->middleware('throttle:auth');
        Route::get('account/b2b/company', [B2BController::class, 'company']);
        Route::patch('account/b2b/company', [B2BController::class, 'updateCompany']);
        Route::get('account/b2b/users', [B2BController::class, 'users']);
        Route::post('account/b2b/users/invite', [B2BController::class, 'invite']);

        Route::get('account/quotes', [QuoteRequestController::class, 'index']);
        Route::post('account/quotes', [QuoteRequestController::class, 'store']);
        Route::get('account/quotes/{quote}', [QuoteRequestController::class, 'show']);
        Route::patch('account/quotes/{quote}', [QuoteRequestController::class, 'update']);
        Route::post('account/quotes/{quote}/submit', [QuoteRequestController::class, 'submit']);
        Route::post('account/quotes/{quote}/accept', [QuoteRequestController::class, 'accept']);
        Route::post('account/quotes/{quote}/messages', [QuoteRequestController::class, 'message']);
        Route::post('account/quotes/{quote}/files', [QuoteRequestController::class, 'file']);
        Route::post('cart/request-quote', CartQuoteController::class);
        Route::post('products/{slug}/request-quote', ProductQuoteController::class);

        Route::get('account/wishlists', [WishlistController::class, 'index']);
        Route::post('account/wishlists', [WishlistController::class, 'store']);
        Route::patch('account/wishlists/{wishlist}', [WishlistController::class, 'update']);
        Route::delete('account/wishlists/{wishlist}', [WishlistController::class, 'destroy']);
        Route::get('account/wishlists/{wishlist}/items', [WishlistController::class, 'items']);
        Route::post('account/wishlists/{wishlist}/items', [WishlistController::class, 'addItem']);
        Route::delete('account/wishlists/{wishlist}/items/{product}', [WishlistController::class, 'removeItem']);
        Route::post('account/wishlist/toggle', [WishlistController::class, 'toggle']);

        Route::post('rewards/redeem', [RewardController::class, 'redeem']);

        Route::middleware('can:manage marketing')->group(function (): void {
            Route::get('analytics/dashboard', AnalyticsController::class);
            Route::get('feeds/status', [FeedController::class, 'status']);
            Route::post('feeds/generate', [FeedController::class, 'generate']);
            Route::get('marketing/events', [MarketingController::class, 'index']);
        });

        Route::middleware('can:view erp logs')->group(function (): void {
            Route::get('admin/erp/status', [ErpController::class, 'status']);
        });

        Route::middleware('can:manage erp')->group(function (): void {
            Route::post('admin/erp/sync/order/{order}', [ErpController::class, 'syncOrder']);
            Route::post('admin/erp/sync/customer/{customer}', [ErpController::class, 'syncCustomer']);
        });

        Route::middleware('can:view supplier import logs')->group(function (): void {
            Route::get('admin/suppliers/import-runs', [SupplierImportController::class, 'index']);
            Route::get('admin/suppliers/{supplier}/import-runs', [SupplierImportController::class, 'supplierRuns']);
        });

        Route::middleware('can:run supplier imports')->group(function (): void {
            Route::post('admin/suppliers/{supplier}/run-import', [SupplierImportController::class, 'run']);
        });

        Route::middleware('can:force supplier imports')->group(function (): void {
            Route::post('admin/suppliers/{supplier}/force-run-import', [SupplierImportController::class, 'forceRun']);
        });
    });

    Route::post('marketing/events', [MarketingController::class, 'store'])->middleware('throttle:marketing');
    Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:newsletter');
    Route::post('newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe'])->middleware('throttle:newsletter');
    Route::get('newsletter/status', [NewsletterController::class, 'status'])->middleware('throttle:newsletter');

    Route::get('navigation/categories', [NavigationController::class, 'categories']);

    Route::get('rewards', [RewardController::class, 'index']);
    Route::get('rewards/{reward}', [RewardController::class, 'show']);

    Route::get('pc-builder', [PcBuilderController::class, 'index']);
    Route::get('pc-builder/builds', [PcBuilderController::class, 'builds']);
    Route::get('pc-builder/builds/{build}', [PcBuilderController::class, 'show']);
    Route::post('pc-builder/builds', [PcBuilderController::class, 'store']);
    Route::patch('pc-builder/builds/{build}', [PcBuilderController::class, 'update']);
    Route::delete('pc-builder/builds/{build}', [PcBuilderController::class, 'destroy']);
    Route::post('pc-builder/builds/{build}/items', [PcBuilderController::class, 'addItem']);
    Route::delete('pc-builder/builds/{build}/items/{item}', [PcBuilderController::class, 'removeItem']);
    Route::get('pc-builder/builds/{build}/compatibility', [PcBuilderController::class, 'compatibility']);
    Route::get('pc-builder/builds/{build}/recommendations', [PcBuilderController::class, 'recommendations']);
    Route::post('pc-builder/builds/{build}/add-to-cart', [PcBuilderController::class, 'addToCart']);
    Route::post('pc-builder/ai-generate', [PcBuilderController::class, 'aiGenerate'])->middleware('throttle:ai');

    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('categories/{slug}/products', [CategoryController::class, 'products']);

    Route::get('brands', [BrandController::class, 'index']);
    Route::get('brands/{slug}', [BrandController::class, 'show']);
    Route::get('brands/{slug}/products', [BrandController::class, 'products']);

    Route::get('bundles', [BundleController::class, 'index']);
    Route::get('bundles/{slug}', [BundleController::class, 'show']);

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{slug}/bundles', [BundleController::class, 'forProduct']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('products/{slug}/related', [ProductController::class, 'related']);
    Route::get('products/{slug}/accessories', [ProductController::class, 'accessories']);
    Route::get('products/{slug}/alternatives', ProductAlternativeController::class)->middleware('throttle:ai');
    Route::get('products/{slug}/reviews', [ProductReviewController::class, 'index']);
    Route::post('products/{product}/price-alerts', [ProductAlertController::class, 'price'])->middleware('throttle:newsletter');
    Route::post('products/{product}/stock-alerts', [ProductAlertController::class, 'stock'])->middleware('throttle:newsletter');
    Route::post('products/{slug}/reviews', [ProductReviewController::class, 'store'])->middleware('throttle:reviews');
    Route::post('reviews/{review}/vote', ReviewVoteController::class)->middleware('throttle:reviews');
    Route::post('reviews/{review}/report', ReviewReportController::class)->middleware('throttle:reviews');

    Route::get('search', [SearchController::class, 'index']);
    Route::get('search/suggestions', [SearchController::class, 'suggestions']);
    Route::get('filters/categories/{slug}', [FilterController::class, 'category']);
    Route::post('compare', CompareController::class);
    Route::get('compare/list', [CompareListController::class, 'show']);
    Route::post('compare/items', [CompareListController::class, 'store']);
    Route::delete('compare/items/{product}', [CompareListController::class, 'destroy']);
    Route::delete('compare/list', [CompareListController::class, 'clear']);
    Route::post('compare/merge', [CompareListController::class, 'merge']);

    Route::middleware('throttle:ai')->group(function (): void {
        Route::post('ai/chat', [AiChatController::class, 'chat']);
        Route::post('ai/search', [AiSearchController::class, 'search']);
        Route::post('ai/compare', [AiSearchController::class, 'compare']);
        Route::post('ai/buying-guide', [AiSearchController::class, 'guide']);
        Route::get('ai/conversations', [AiChatController::class, 'index']);
        Route::get('ai/conversations/{conversation}', [AiChatController::class, 'show']);
        Route::delete('ai/conversations/{conversation}', [AiChatController::class, 'destroy']);
    });

    Route::get('seo/product/{slug}', [SeoController::class, 'product']);
    Route::get('seo/category/{slug}', [SeoController::class, 'category']);
    Route::get('seo/brand/{slug}', [SeoController::class, 'brand']);

    Route::get('blog', [BlogController::class, 'index']);
    Route::get('blog/categories', [BlogCategoryController::class, 'index']);
    Route::get('blog/categories/{slug}', [BlogCategoryController::class, 'show']);
    Route::get('blog/categories/{slug}/posts', [BlogCategoryController::class, 'posts']);
    Route::get('blog/tags', [BlogTagController::class, 'index']);
    Route::get('blog/tags/{slug}', [BlogTagController::class, 'show']);
    Route::get('blog/{slug}', [BlogController::class, 'show']);

    Route::get('pages/{slug}', [SeoPageController::class, 'show']);
    Route::get('seo-pages/{slug}', [SeoPageController::class, 'show']);

    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/email', [CartController::class, 'email'])->middleware('throttle:newsletter');
    Route::post('cart/recover/{token}', [CartController::class, 'recover'])->middleware('throttle:newsletter');
    Route::post('cart/coupon', [CartController::class, 'applyCoupon'])->middleware('throttle:coupon');
    Route::delete('cart/coupon', [CartController::class, 'removeCoupon'])->middleware('throttle:coupon');
    Route::post('cart/items', [CartController::class, 'store']);
    Route::patch('cart/items/{item}', [CartController::class, 'update']);
    Route::delete('cart/items/{item}', [CartController::class, 'destroy']);
    Route::post('cart/bundles', [CartBundleController::class, 'store']);
    Route::patch('cart/bundles/{bundle}', [CartBundleController::class, 'update']);
    Route::delete('cart/bundles/{bundle}', [CartBundleController::class, 'destroy']);
    Route::delete('cart', [CartController::class, 'clear']);

    Route::post('checkout', CheckoutController::class);

    Route::get('shipping/providers', [ShippingController::class, 'providers']);
    Route::get('shipping/methods', [ShippingController::class, 'methods']);
    Route::get('shipping/offices', [ShippingController::class, 'offices']);
    Route::post('shipping/calculate', [ShippingController::class, 'calculate']);

    Route::get('payments/methods', [PaymentController::class, 'methods']);
    Route::post('payments/initiate', [PaymentController::class, 'initiate']);
    Route::post('payments/webhook/{provider}', [PaymentController::class, 'webhook']);
});
