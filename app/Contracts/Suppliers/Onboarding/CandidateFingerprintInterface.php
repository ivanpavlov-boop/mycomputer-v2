<?php

namespace App\Contracts\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\NormalizedSupplierRecord;

interface CandidateFingerprintInterface
{
    public const CONTRACT_VERSION = 'supplier-candidate-fingerprint-v1';

    /** @param iterable<int, NormalizedSupplierRecord> $records */
    public function fingerprint(iterable $records): string;
}
