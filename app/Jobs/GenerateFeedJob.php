<?php

namespace App\Jobs;

use App\Models\FeedExport;
use App\Services\Marketing\FacebookCatalogService;
use App\Services\Marketing\MerchantFeedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateFeedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $feedType)
    {
        $this->onQueue('exports');
    }

    public function viaQueue(): string
    {
        return 'exports';
    }

    public function handle(MerchantFeedService $merchantFeed, FacebookCatalogService $facebookCatalog): void
    {
        match ($this->feedType) {
            'facebook_catalog' => $facebookCatalog->generate(),
            'google_merchant' => $merchantFeed->generate(),
            default => throw new \InvalidArgumentException('Unsupported feed type.'),
        };
    }

    public function failed(Throwable $exception): void
    {
        FeedExport::query()->create([
            'feed_type' => $this->feedType,
            'status' => 'failed',
            'products_count' => 0,
            'generated_at' => now(),
        ]);

        report($exception);
    }
}
