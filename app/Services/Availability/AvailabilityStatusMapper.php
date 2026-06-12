<?php

namespace App\Services\Availability;

use App\Models\AvailabilityStatus;
use App\Models\AvailabilityStatusMapping;

class AvailabilityStatusMapper
{
    public function __construct(private readonly AvailabilityStatusService $statuses) {}

    public function map(string $sourceType, ?string $sourceCode, ?string $externalStatus): ?AvailabilityStatus
    {
        $externalStatus = $this->normalize($externalStatus);

        if ($externalStatus === null) {
            return null;
        }

        $sourceCode = filled($sourceCode) ? trim((string) $sourceCode) : null;

        return AvailabilityStatusMapping::query()
            ->active()
            ->whereHas('availabilityStatus', fn ($query) => $query->where('is_active', true))
            ->where('source_type', $sourceType)
            ->where(function ($query) use ($sourceCode, $externalStatus): void {
                $query
                    ->where(function ($query) use ($sourceCode, $externalStatus): void {
                        $query->where('source_code', $sourceCode)->where('external_status', $externalStatus);
                    })
                    ->orWhere(function ($query) use ($externalStatus): void {
                        $query->whereNull('source_code')->where('external_status', $externalStatus);
                    });
            })
            ->orderBy('priority')
            ->first()
            ?->availabilityStatus;
    }

    public function mapWithFallback(string $sourceType, ?string $sourceCode, ?string $externalStatus, ?int $quantity = null): ?AvailabilityStatus
    {
        return $this->map($sourceType, $sourceCode, $externalStatus)
            ?? $this->map('manual', null, $externalStatus)
            ?? $this->statuses->automaticForQuantity($quantity)
            ?? $this->statuses->default();
    }

    private function normalize(?string $externalStatus): ?string
    {
        $externalStatus = trim((string) $externalStatus);

        return $externalStatus === '' ? null : $externalStatus;
    }
}
