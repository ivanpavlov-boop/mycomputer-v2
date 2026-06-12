<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\ProductRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendation_generation(): void
    {
        Product::factory()->create(['name' => 'Gaming Laptop RTX', 'price' => 2400]);

        $this->postJson('/api/v1/ai/search', ['query' => 'gaming laptop under 2500 BGN'])
            ->assertOk()
            ->assertJsonPath('data.intent.price_max', 2500)
            ->assertJsonStructure(['data' => ['summary', 'reasoning', 'products']]);

        $this->assertDatabaseHas('ai_recommendation_logs', ['recommendation_type' => 'product_recommendation']);
    }

    public function test_alternatives_generation(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 2000, 'slug' => 'base-laptop']);
        Product::factory()->create(['category_id' => $category->id, 'price' => 1500]);
        Product::factory()->create(['category_id' => $category->id, 'price' => 2500]);

        $this->getJson("/api/v1/products/{$product->slug}/alternatives")
            ->assertOk()
            ->assertJsonCount(1, 'data.cheaper_alternatives')
            ->assertJsonCount(1, 'data.better_alternatives');
    }

    public function test_conversation_storage(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['message' => 'Need laptop for AutoCAD'])
            ->assertOk()
            ->assertJsonCount(2, 'data.messages');

        $this->assertDatabaseHas('ai_conversations', ['user_id' => $user->id]);
        $this->assertDatabaseCount('ai_messages', 2);
    }

    public function test_ai_search_intent_parsing(): void
    {
        $intent = app(ProductRecommendationService::class)->parseIntent('Need a laptop for architecture under 3000 BGN');

        $this->assertSame(3000, $intent['price_max']);
        $this->assertContains('autocad', $intent['category_keywords']);
        $this->assertContains('laptop', $intent['category_keywords']);
    }

    public function test_compare_explanation_generation(): void
    {
        $products = Product::factory()->count(2)->create();

        $this->postJson('/api/v1/ai/compare', ['product_ids' => $products->pluck('id')->all()])
            ->assertOk()
            ->assertJsonStructure(['data' => ['comparison', 'ai_explanation' => ['summary', 'strengths', 'weaknesses', 'use_cases']]]);
    }

    public function test_buying_guide_generation(): void
    {
        $this->postJson('/api/v1/ai/buying-guide', ['topic' => 'how much RAM do I need'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'mock')
            ->assertJsonStructure(['data' => ['title', 'content', 'checklist']]);
    }
}
