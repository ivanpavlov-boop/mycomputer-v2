<?php

namespace Tests\Feature;

use App\Filament\Resources\ContentPages\ContentPageResource;
use App\Filament\Resources\ContentTemplates\ContentTemplateResource;
use App\Filament\Resources\ReusableContentBlocks\ReusableContentBlockResource;
use App\Models\ContentPage;
use App\Models\ContentTemplate;
use App\Models\ReusableContentBlock;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentBlocksCmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_content_page_is_returned_and_draft_is_hidden(): void
    {
        $this->contentPage(['slug' => 'published-campaign']);
        $this->contentPage(['slug' => 'draft-campaign', 'status' => 'draft', 'published_at' => null]);

        $this->getJson('/api/v1/content/pages/published-campaign')->assertOk()->assertJsonPath('data.slug', 'published-campaign');
        $this->getJson('/api/v1/content/pages/draft-campaign')->assertNotFound();
    }

    public function test_scheduled_content_page_is_hidden_before_publish_date(): void
    {
        $this->contentPage(['slug' => 'future-campaign', 'status' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->getJson('/api/v1/content/pages/future-campaign')->assertNotFound();
    }

    public function test_homepage_endpoint_renders_active_blocks_with_responsive_payload(): void
    {
        $page = $this->contentPage(['slug' => 'home', 'page_type' => 'homepage']);
        $page->blocks()->create([
            'block_type' => 'hero',
            'title' => 'Home Hero',
            'content' => ['heading' => 'Homepage campaign', 'desktop_image' => 'desktop.jpg'],
            'responsive_settings' => ['mobile' => ['visible' => true, 'layout' => ['columns' => 1]]],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/content/homepage')
            ->assertOk()
            ->assertJsonPath('data.page_type', 'homepage')
            ->assertJsonPath('data.blocks.0.type', 'hero')
            ->assertJsonPath('data.blocks.0.responsive.mobile.layout.columns', 1)
            ->assertJsonPath('data.blocks.0.images.mobile', 'desktop.jpg');
    }

    public function test_inactive_and_all_device_hidden_blocks_are_not_rendered(): void
    {
        $page = $this->contentPage(['slug' => 'hidden-blocks']);
        $page->blocks()->create([
            'block_type' => 'hero',
            'content' => ['heading' => 'Visible'],
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $page->blocks()->create([
            'block_type' => 'hero',
            'content' => ['heading' => 'Inactive'],
            'sort_order' => 2,
            'is_active' => false,
        ]);
        $page->blocks()->create([
            'block_type' => 'hero',
            'content' => ['heading' => 'Hidden everywhere'],
            'responsive_settings' => [
                'desktop' => ['visible' => false],
                'tablet' => ['visible' => false],
                'mobile' => ['visible' => false],
            ],
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/content/pages/hidden-blocks')
            ->assertOk()
            ->assertJsonCount(1, 'data.blocks')
            ->assertJsonPath('data.blocks.0.data.heading', 'Visible');
    }

    public function test_cms_html_is_sanitized_and_safe_formatting_is_preserved(): void
    {
        $page = $this->contentPage(['slug' => 'sanitized']);
        $page->blocks()->create([
            'block_type' => 'rich_text',
            'content' => [
                'body' => '<p onclick="alert(1)">Hello <strong>world</strong><script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://example.com" target="_blank">safe</a><img src="x.jpg" onerror="alert(1)" alt="x">',
            ],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $body = $this->getJson('/api/v1/content/pages/sanitized')
            ->assertOk()
            ->json('data.blocks.0.data.body');

        $this->assertStringContainsString('<strong>world</strong>', $body);
        $this->assertStringContainsString('href="https://example.com"', $body);
        $this->assertStringContainsString('rel="noopener noreferrer"', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('onerror', $body);
    }

    public function test_custom_html_is_sanitized(): void
    {
        $page = $this->contentPage(['slug' => 'custom-html']);
        $page->blocks()->create([
            'block_type' => 'custom_html',
            'content' => ['html' => '<div><em>Safe</em><iframe src="https://example.com"></iframe><a href="javascript:alert(1)">bad</a></div>'],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $html = $this->getJson('/api/v1/content/pages/custom-html')
            ->assertOk()
            ->json('data.blocks.0.data.html');

        $this->assertStringContainsString('<em>Safe</em>', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_reusable_block_content_is_merged_into_page_block(): void
    {
        $reusable = ReusableContentBlock::query()->create([
            'name' => 'Reusable CTA',
            'block_type' => 'cta',
            'content' => ['heading' => 'Reusable heading', 'button_label' => 'Shop'],
            'settings' => ['source' => 'featured'],
            'responsive_settings' => ['desktop' => ['visible' => true]],
        ]);
        $page = $this->contentPage(['slug' => 'with-reusable']);
        $page->blocks()->create([
            'reusable_block_id' => $reusable->id,
            'block_type' => 'cta',
            'content' => ['text' => 'Local override'],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/content/pages/with-reusable')
            ->assertOk()
            ->assertJsonPath('data.blocks.0.data.heading', 'Reusable heading')
            ->assertJsonPath('data.blocks.0.data.text', 'Local override');
    }

    public function test_faq_schema_is_generated_from_visible_faq_blocks_only(): void
    {
        $page = $this->contentPage(['slug' => 'faq-schema']);
        $page->blocks()->create([
            'block_type' => 'faq',
            'content' => [
                'items' => [
                    ['question' => 'How long is delivery?', 'answer' => '<strong>One day</strong>'],
                ],
            ],
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $page->blocks()->create([
            'block_type' => 'faq',
            'content' => [
                'items' => [
                    ['question' => 'Hidden question?', 'answer' => 'Hidden answer'],
                ],
            ],
            'sort_order' => 2,
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/content/pages/faq-schema')
            ->assertOk()
            ->assertJsonPath('data.schema.@type', 'FAQPage')
            ->assertJsonPath('data.schema.mainEntity.0.@type', 'Question')
            ->assertJsonPath('data.schema.mainEntity.0.name', 'How long is delivery?')
            ->assertJsonPath('data.schema.mainEntity.0.acceptedAnswer.text', 'One day')
            ->assertJsonCount(1, 'data.schema.mainEntity');
    }

    public function test_responsive_picture_uses_mobile_image_as_img_fallback(): void
    {
        $component = file_get_contents(base_path('frontend/app/components/content/CmsResponsivePicture.vue'));

        $this->assertStringContainsString('media="(min-width: 1200px)"', $component);
        $this->assertStringContainsString('media="(min-width: 768px)"', $component);
        $this->assertStringContainsString('images.mobile || images.tablet || images.desktop', $component);
    }

    public function test_visibility_rules_hide_guest_only_block_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $page = $this->contentPage(['slug' => 'visibility']);
        $page->blocks()->create([
            'block_type' => 'coupon_banner',
            'content' => ['heading' => 'Guest coupon'],
            'visibility_rules' => ['guest_only' => true],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/content/pages/visibility')->assertOk()->assertJsonCount(1, 'data.blocks');
        $this->actingAs($user)->getJson('/api/v1/content/pages/visibility')->assertOk()->assertJsonCount(0, 'data.blocks');
    }

    public function test_templates_and_block_types_are_available(): void
    {
        ContentTemplate::query()->create([
            'name' => 'Black Friday',
            'slug' => 'black-friday',
            'template_data' => ['blocks' => [['block_type' => 'campaign_hero']]],
        ]);

        $this->getJson('/api/v1/content/templates')->assertOk()->assertJsonPath('data.0.slug', 'black-friday');

        $response = $this->getJson('/api/v1/content/block-types')->assertOk();

        $this->assertContains('hero', $response->json('data.marketing'));
        $this->assertContains('promo_hero', $response->json('data.marketing'));
        $this->assertContains('campaign_hero', $response->json('data.marketing'));
    }

    public function test_content_pages_are_included_in_sitemap(): void
    {
        $this->contentPage(['slug' => 'sitemap-campaign']);

        $this->get('/sitemap.xml')->assertOk()->assertSee('/pages/sitemap-campaign', false);
    }

    public function test_filament_permissions_for_content_resources(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create();
        $customer = User::factory()->create();
        $manager->assignRole('manager');
        $customer->assignRole('customer');

        $this->actingAs($manager);
        $this->assertTrue(ContentPageResource::canViewAny());
        $this->assertTrue(ContentTemplateResource::canViewAny());
        $this->assertTrue(ReusableContentBlockResource::canViewAny());

        $this->actingAs($customer);
        $this->assertFalse(ContentPageResource::canViewAny());
        $this->assertFalse(ContentTemplateResource::canViewAny());
        $this->assertFalse(ReusableContentBlockResource::canViewAny());
    }

    private function contentPage(array $overrides = []): ContentPage
    {
        return ContentPage::query()->create(array_merge([
            'title' => 'Campaign',
            'slug' => fake()->unique()->slug(),
            'page_type' => 'campaign_page',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ], $overrides));
    }
}
