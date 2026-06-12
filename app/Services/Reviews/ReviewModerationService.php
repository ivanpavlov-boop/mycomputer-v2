<?php

namespace App\Services\Reviews;

use App\Models\ProductReview;
use App\Models\ProductReviewReport;

class ReviewModerationService
{
    public function approve(ProductReview $review): void
    {
        $review->update([
            'status' => 'approved',
            'approved_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);
    }

    public function reject(ProductReview $review, ?string $reason = null): void
    {
        $review->update([
            'status' => 'rejected',
            'approved_at' => null,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function spam(ProductReview $review): void
    {
        $review->update([
            'status' => 'spam',
            'approved_at' => null,
            'rejected_at' => now(),
        ]);
    }

    public function markReportReviewed(ProductReviewReport $report): void
    {
        $report->update(['status' => 'reviewed']);
    }

    public function dismissReport(ProductReviewReport $report): void
    {
        $report->update(['status' => 'dismissed']);
    }
}
