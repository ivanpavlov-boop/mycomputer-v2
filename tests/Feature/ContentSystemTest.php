<?php

namespace Tests\Feature;

use App\Filament\Resources\BlogPosts\BlogPostResource;
use App\Filament\Resources\SeoPages\SeoPageResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\SeoPage;
use App\Models\User;
use App\Support\Content\ResponsiveBlockDefaults;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_blog_list_shows_only_published_posts(): void
    {
        $published = $this->blogPost(['title' => 'Published guide', 'slug' => 'published-guide']);
        $this->blogPost(['title' => 'Draft guide', 'slug' => 'draft-guide', 'status' => 'draft', 'published_at' => null]);

        $this->getJson('/api/v1/blog')
            ->assertOk()
            ->assertJsonFragment(['slug' => $published->slug])
            ->assertJsonMissing(['slug' => 'draft-guide']);
    }

    public function test_scheduled_posts_are_hidden_before_publish_date(): void
    {
        $this->blogPost(['title' => 'Future guide', 'slug' => 'future-guide', 'status' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->getJson('/api/v1/blog')->assertOk()->assertJsonMissing(['slug' => 'future-guide']);
    }

    public function test_blog_post_detail_and_views(): void
    {
        $post = $this->blogPost(['slug' => 'laptop-guide']);

        $this->getJson('/api/v1/blog/laptop-guide')
            ->assertOk()
            ->assertJsonPath('data.slug', 'laptop-guide')
            ->assertJsonPath('data.views_count', 1);

        $this->assertSame(1, $post->refresh()->views_count);
    }

    public function test_public_content_relations_exclude_non_public_products(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $visible = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'sku' => 'CONTENT-PUBLIC-001',
        ]);
        $hidden = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'sku' => 'CONTENT-HIDDEN-001',
            'workflow_status' => Product::WORKFLOW_APPROVED,
            'product_status' => 'hidden',
            'active' => false,
        ]);

        $post = $this->blogPost(['slug' => 'workflow-related-products']);
        $post->relatedProducts()->attach([$visible->id, $hidden->id]);

        $page = SeoPage::query()->create($this->pagePayload(['slug' => 'workflow-related-seo-page']));
        $page->relatedProducts()->attach([$visible->id, $hidden->id]);

        $this->getJson('/api/v1/blog/workflow-related-products')
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);

        $this->getJson('/api/v1/seo-pages/workflow-related-seo-page')
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => $hidden->sku]);
    }

    public function test_blog_category_and_tag_pages(): void
    {
        $category = BlogCategory::query()->create(['name' => 'Guides', 'slug' => 'guides', 'is_active' => true]);
        $tag = BlogTag::query()->create(['name' => 'Laptops', 'slug' => 'laptops']);
        $post = $this->blogPost(['blog_category_id' => $category->id, 'slug' => 'student-laptop']);
        $post->tags()->attach($tag);

        $this->getJson('/api/v1/blog/categories/guides')->assertOk()->assertJsonPath('data.slug', 'guides');
        $this->getJson('/api/v1/blog/categories/guides/posts')->assertOk()->assertJsonFragment(['slug' => 'student-laptop']);
        $this->getJson('/api/v1/blog/tags/laptops')->assertOk()->assertJsonFragment(['slug' => 'student-laptop']);
    }

    public function test_seo_page_public_access_and_draft_hidden(): void
    {
        SeoPage::query()->create($this->pagePayload(['slug' => 'laptopi-za-studenti']));
        SeoPage::query()->create($this->pagePayload(['slug' => 'draft-page', 'status' => 'draft', 'published_at' => null]));

        $this->getJson('/api/v1/seo-pages/laptopi-za-studenti')->assertOk()->assertJsonPath('data.slug', 'laptopi-za-studenti');
        $this->getJson('/api/v1/seo-pages/draft-page')->assertNotFound();
    }

    public function test_responsive_cms_blocks_are_persisted_and_returned_in_api_payload(): void
    {
        SeoPage::query()->create($this->pagePayload([
            'slug' => 'responsive-landing',
            'content' => json_encode([
                'blocks' => [[
                    'type' => 'hero',
                    'data' => [
                        'heading' => 'Gaming laptop campaign',
                        'desktop_image' => 'cms/desktop/hero.jpg',
                        'mobile_image' => 'cms/mobile/hero.jpg',
                        'responsive' => [
                            'desktop' => [
                                'visible' => true,
                                'layout' => ['columns' => 4, 'spacing' => 'lg', 'alignment' => 'center'],
                                'typography' => ['heading_size' => '4xl'],
                            ],
                            'tablet' => [
                                'visible' => true,
                                'layout' => ['columns' => 2],
                            ],
                            'mobile' => [
                                'visible' => false,
                                'layout' => ['columns' => 1],
                                'buttons' => ['layout' => 'stacked', 'full_width' => true],
                            ],
                        ],
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]));

        $this->getJson('/api/v1/seo-pages/responsive-landing')
            ->assertOk()
            ->assertJsonPath('data.preview_modes', ['desktop', 'tablet', 'mobile'])
            ->assertJsonPath('data.responsive_profiles.desktop.min_width', 1200)
            ->assertJsonPath('data.content.0.type', 'hero')
            ->assertJsonPath('data.content.0.responsive.desktop.layout.columns', 4)
            ->assertJsonPath('data.content.0.responsive.tablet.layout.columns', 2)
            ->assertJsonPath('data.content.0.responsive.mobile.visible', false)
            ->assertJsonPath('data.content.0.responsive.mobile.buttons.full_width', true)
            ->assertJsonPath('data.content.0.images.desktop', 'cms/desktop/hero.jpg')
            ->assertJsonPath('data.content.0.images.mobile', 'cms/mobile/hero.jpg');
    }

    public function test_responsive_image_fallback_uses_mobile_tablet_desktop_order(): void
    {
        $desktopOnly = ResponsiveBlockDefaults::responsiveImages(['desktop_image' => 'desktop.jpg']);
        $tabletAndDesktop = ResponsiveBlockDefaults::responsiveImages(['desktop_image' => 'desktop.jpg', 'tablet_image' => 'tablet.jpg']);

        $this->assertSame('desktop.jpg', $desktopOnly['mobile']);
        $this->assertSame('desktop.jpg', $desktopOnly['tablet']);
        $this->assertSame('tablet.jpg', $tabletAndDesktop['mobile']);
        $this->assertSame('tablet.jpg', $tabletAndDesktop['tablet']);
        $this->assertSame('desktop.jpg', $tabletAndDesktop['desktop']);
    }

    public function test_legacy_html_content_still_renders_as_string_payload(): void
    {
        SeoPage::query()->create($this->pagePayload([
            'slug' => 'legacy-html-page',
            'content' => '<p>Legacy SEO content.</p>',
        ]));

        $this->getJson('/api/v1/seo-pages/legacy-html-page')
            ->assertOk()
            ->assertJsonPath('data.content', '<p>Legacy SEO content.</p>');
    }

    public function test_redirect_works(): void
    {
        Redirect::query()->create([
            'source_url' => '/old-laptops',
            'target_url' => '/guide/laptopi',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->get('/old-laptops')->assertRedirect('/guide/laptopi')->assertStatus(301);
    }

    public function test_sitemap_includes_products_categories_brands_blog_and_seo_pages(): void
    {
        $product = Product::factory()->create(['slug' => 'sitemap-product']);
        $category = Category::factory()->create(['slug' => 'sitemap-category', 'is_active' => true]);
        $brand = Brand::factory()->create(['slug' => 'sitemap-brand', 'is_active' => true]);
        $post = $this->blogPost(['slug' => 'sitemap-post']);
        SeoPage::query()->create($this->pagePayload(['slug' => 'sitemap-page']));

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee("/p/{$product->slug}", false)
            ->assertSee("/c/{$category->slug}", false)
            ->assertSee("/brand/{$brand->slug}", false)
            ->assertSee("/blog/{$post->slug}", false)
            ->assertSee('/guide/sitemap-page', false);
    }

    public function test_open_redirect_protection(): void
    {
        Redirect::query()->create([
            'source_url' => '/bad-redirect',
            'target_url' => 'https://evil.example/phishing',
            'status_code' => 302,
            'is_active' => true,
        ]);

        $this->get('/bad-redirect')->assertUnprocessable();
    }

    public function test_filament_permissions_for_blog_and_pages(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create();
        $customer = User::factory()->create();
        $manager->assignRole('manager');
        $customer->assignRole('customer');

        $this->actingAs($manager);
        $this->assertTrue(BlogPostResource::canViewAny());
        $this->assertTrue(SeoPageResource::canViewAny());

        $this->actingAs($customer);
        $this->assertFalse(BlogPostResource::canViewAny());
        $this->assertFalse(SeoPageResource::canViewAny());
    }

    private function blogPost(array $overrides = []): BlogPost
    {
        return BlogPost::query()->create(array_merge([
            'title' => 'Buying guide',
            'slug' => fake()->unique()->slug(),
            'content' => '<p>Useful content for buyers.</p>',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ], $overrides));
    }

    private function pagePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Лаптопи за студенти',
            'slug' => fake()->unique()->slug(),
            'type' => 'buying_guide',
            'content' => '<p>SEO content.</p>',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ], $overrides);
    }
}
