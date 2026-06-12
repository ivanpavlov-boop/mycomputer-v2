<?php

namespace App\Services\Content;

class BlockRegistry
{
    public function all(): array
    {
        return [
            'layout' => ['section', 'container', 'two_columns', 'three_columns', 'four_columns', 'cards_grid', 'masonry_grid', 'tabs', 'accordion', 'slider', 'spacer', 'divider', 'sticky_cta', 'hero_split_layout', 'mega_banner_layout'],
            'marketing' => ['hero', 'promo_hero', 'campaign_hero', 'promo_strip', 'coupon_banner', 'newsletter', 'cta', 'countdown', 'brand_campaign', 'seasonal_campaign', 'flash_sale', 'daily_deals', 'free_shipping_banner', 'gift_promotion_banner'],
            'commerce' => ['product_grid', 'product_carousel', 'product_slider', 'featured_products', 'new_arrivals', 'best_sellers', 'promo_products', 'clearance_products', 'top_rated_products', 'recommended_products', 'recently_viewed_products', 'related_products', 'product_collection', 'product_comparison_cta'],
            'bundles' => ['bundle_grid', 'bundle_carousel', 'bundle_hero', 'bundle_promotion_banner', 'bundle_comparison'],
            'categories' => ['category_grid', 'category_carousel', 'category_cards', 'featured_categories', 'category_hero', 'category_intro', 'category_seo_text'],
            'brands' => ['brand_grid', 'brand_carousel', 'brand_story', 'featured_brands', 'brand_campaign'],
            'trust' => ['why_buy_from_us', 'trust_badges', 'delivery_information', 'payment_methods', 'warranty_information', 'returns_policy', 'service_cta', 'expert_consultation', 'testimonials', 'reviews_summary'],
            'b2b' => ['b2b_hero', 'request_quote_cta', 'corporate_benefits', 'company_registration_cta', 'b2b_testimonials', 'b2b_pricing_cta'],
            'tech_store' => ['pc_builder_cta', 'laptop_finder_cta', 'printer_finder_cta', 'gaming_setup_cta', 'service_cta', 'leasing_cta', 'ai_assistant_cta', 'trade_in_cta'],
            'content' => ['rich_text', 'custom_html', 'blog_posts', 'featured_articles', 'buying_guide', 'faq', 'pros_cons', 'specification_table', 'video_review', 'brand_story', 'category_story'],
        ];
    }

    public function flat(): array
    {
        return collect($this->all())->flatten()->unique()->values()->all();
    }

    public function options(): array
    {
        return collect($this->flat())->mapWithKeys(fn (string $type): array => [$type => str($type)->replace('_', ' ')->title()->toString()])->all();
    }
}
