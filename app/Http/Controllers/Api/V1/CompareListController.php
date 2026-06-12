<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCompareItemRequest;
use App\Http\Resources\CompareListResource;
use App\Models\Product;
use App\Services\Products\CompareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompareListController extends Controller
{
    public function __construct(private readonly CompareService $compare) {}

    public function show(Request $request): CompareListResource
    {
        return CompareListResource::make($this->compare->resolve($this->user(), $this->sessionId($request)));
    }

    public function store(AddCompareItemRequest $request): CompareListResource
    {
        $list = $this->compare->resolve($this->user(), $this->sessionId($request));
        $product = Product::query()->findOrFail($request->integer('product_id'));

        return CompareListResource::make($this->compare->add($list, $product));
    }

    public function destroy(Request $request, Product $product): CompareListResource
    {
        $list = $this->compare->resolve($this->user(), $this->sessionId($request));

        return CompareListResource::make($this->compare->remove($list, $product));
    }

    public function clear(Request $request): CompareListResource
    {
        $list = $this->compare->resolve($this->user(), $this->sessionId($request));

        return CompareListResource::make($this->compare->clear($list));
    }

    public function merge(Request $request): CompareListResource
    {
        $user = $this->user();
        abort_unless($user, 401);

        return CompareListResource::make($this->compare->merge($user, $this->sessionId($request)));
    }

    private function sessionId(Request $request): ?string
    {
        return $request->header('X-Compare-Session');
    }

    private function user()
    {
        return Auth::guard('sanctum')->user();
    }
}
