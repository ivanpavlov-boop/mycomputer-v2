<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CustomerAddressResource::collection(
                $request->user()->addresses()->latest('is_default')->latest()->get()
            ),
        ]);
    }

    public function store(CustomerAddressRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($validated['is_default'] ?? false) {
            $this->clearDefault($request->user()->id, $validated['type']);
        }

        $address = $request->user()->addresses()->create($validated);

        return response()->json([
            'data' => CustomerAddressResource::make($address),
        ], 201);
    }

    public function update(CustomerAddressRequest $request, CustomerAddress $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);

        $validated = $request->validated();

        if ($validated['is_default'] ?? false) {
            $this->clearDefault($request->user()->id, $validated['type'], $address->id);
        }

        $address->update($validated);

        return response()->json([
            'data' => CustomerAddressResource::make($address->refresh()),
        ]);
    }

    public function destroy(Request $request, CustomerAddress $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);

        $address->delete();

        return response()->json(['message' => 'Address deleted.']);
    }

    private function clearDefault(int $userId, string $type, ?int $exceptId = null): void
    {
        CustomerAddress::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }
}
