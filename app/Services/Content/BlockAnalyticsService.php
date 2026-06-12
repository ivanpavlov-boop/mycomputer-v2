<?php

namespace App\Services\Content;

use App\Models\ContentBlock;
use App\Models\ContentPage;
use App\Services\Marketing\MarketingEventService;

class BlockAnalyticsService
{
    public function __construct(private readonly MarketingEventService $events) {}

    public function pageViewed(ContentPage $page, ?int $userId = null, ?string $sessionId = null): void
    {
        $this->events->log('content_page_viewed', 'internal', ['content_page_id' => $page->id, 'slug' => $page->slug], null, $sessionId);
    }

    public function blockClicked(ContentBlock $block, ?int $userId = null, ?string $sessionId = null): void
    {
        $this->events->log('block_clicked', 'internal', ['content_block_id' => $block->id, 'block_type' => $block->block_type], null, $sessionId);
    }
}
