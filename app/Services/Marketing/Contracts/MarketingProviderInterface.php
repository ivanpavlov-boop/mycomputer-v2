<?php

namespace App\Services\Marketing\Contracts;

use App\Models\ConversionLog;
use App\Models\MarketingEvent;

interface MarketingProviderInterface
{
    public function source(): string;

    public function supports(string $eventName): bool;

    public function track(MarketingEvent $event): array;

    public function convert(ConversionLog $conversion): array;
}
