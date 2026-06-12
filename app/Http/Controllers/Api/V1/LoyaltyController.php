<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyAccountResource;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __invoke(Request $request, LoyaltyService $loyalty): LoyaltyAccountResource
    {
        return LoyaltyAccountResource::make($loyalty->account($request->user()));
    }
}
