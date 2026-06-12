<?php

namespace App\Jobs;

use App\Models\Wishlist;
use App\Services\Email\EmailMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWishlistReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $wishlistId)
    {
        $this->onQueue('emails');
    }

    public function handle(EmailMarketingService $emailMarketing): void
    {
        $wishlist = Wishlist::query()->with(['user', 'items.product'])->find($this->wishlistId);
        if ($wishlist?->user?->email) {
            $emailMarketing->queue($wishlist->user->email, 'wishlist_reminder', ['wishlist' => $wishlist]);
        }
    }
}
