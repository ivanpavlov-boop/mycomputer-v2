<?php

namespace App\Services\Shipping;

use App\Models\ShippingOffice;
use App\Models\ShippingProvider;
use Illuminate\Database\Eloquent\Collection;

class ShippingOfficeService
{
    public function __construct(private readonly ShippingService $shippingService) {}

    public function sync(ShippingProvider $provider): int
    {
        $offices = $this->shippingService->provider($provider)->getOffices();

        foreach ($offices as $office) {
            $provider->offices()->updateOrCreate(
                ['office_id' => $office['office_id']],
                [
                    'name' => $office['name'],
                    'city' => $office['city'],
                    'postcode' => $office['postcode'] ?? null,
                    'address' => $office['address'],
                    'phone' => $office['phone'] ?? null,
                    'latitude' => $office['latitude'] ?? null,
                    'longitude' => $office['longitude'] ?? null,
                    'raw_data' => $office,
                    'status' => 'active',
                ],
            );
        }

        return count($offices);
    }

    public function search(array $filters): Collection
    {
        return ShippingOffice::query()
            ->with('provider')
            ->where('status', 'active')
            ->when(filled($filters['provider'] ?? null), fn ($query) => $query->whereHas('provider', fn ($provider) => $provider->where('code', $filters['provider'])))
            ->when(filled($filters['city'] ?? null), fn ($query) => $query->where('city', 'like', '%'.$filters['city'].'%'))
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%')->orWhere('address', 'like', '%'.$filters['search'].'%')))
            ->orderBy('city')
            ->orderBy('name')
            ->limit(50)
            ->get();
    }
}
