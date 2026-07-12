<?php

namespace App\Contracts\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\DriverInspection;
use App\Data\Suppliers\Onboarding\NormalizedSupplierRecord;
use App\Data\Suppliers\Onboarding\SupplierFeedProfile;
use App\Data\Suppliers\Onboarding\SupplierFeedSource;

interface SupplierFeedDriverInterface
{
    public const CONTRACT_VERSION = 'supplier-feed-driver-v1';

    public function key(): string;

    public function contractVersion(): string;

    /** @return array<int, string> */
    public function supportedSourceFormats(): array;

    public function supports(SupplierFeedSource $source, SupplierFeedProfile $profile): bool;

    public function inspect(SupplierFeedSource $source, SupplierFeedProfile $profile): DriverInspection;

    /** @return iterable<int, NormalizedSupplierRecord> */
    public function records(SupplierFeedSource $source, SupplierFeedProfile $profile): iterable;

    /** @return array<string, scalar|array|null> */
    public function diagnostics(): array;
}
