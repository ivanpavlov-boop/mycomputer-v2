<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Http\Resources\PcBuildResource;
use App\Http\Resources\PcCompatibilityResource;
use App\Http\Resources\ProductCardResource;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\Product;
use App\Services\Cart\CartContextResolver;
use App\Services\Cart\CartPricingRefreshService;
use App\Services\Cart\CartService;
use App\Services\PcBuilder\AiBuildGeneratorService;
use App\Services\PcBuilder\BuildRecommendationService;
use App\Services\PcBuilder\CompatibilityService;
use App\Services\PcBuilder\PcBuilderService;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class PcBuilderController extends Controller
{
    public function __construct(
        private readonly PcBuilderService $builder,
        private readonly CompatibilityService $compatibility,
        private readonly BuildRecommendationService $recommendations,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'component_types' => PcBuildItem::COMPONENT_TYPES,
                'statuses' => PcBuild::STATUSES,
                'templates' => $this->recommendations->presets(),
            ],
        ]);
    }

    public function builds(Request $request): AnonymousResourceCollection
    {
        return PcBuildResource::collection(
            $this->builder->ownedQuery($this->user(), $this->sessionId($request))
                ->with(['items.product.brand', 'items.product.category', 'items.product.images'])
                ->latest()
                ->get()
        );
    }

    public function show(Request $request, PcBuild $build): PcBuildResource
    {
        $this->authorizeBuild($request, $build);

        return PcBuildResource::make($build->load(['items.product.brand', 'items.product.category', 'items.product.images']));
    }

    public function store(Request $request): PcBuildResource
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:draft,saved,shared,ordered'],
        ]);

        return PcBuildResource::make($this->builder->create($data, $this->user(), $this->sessionId($request)));
    }

    public function update(Request $request, PcBuild $build): PcBuildResource
    {
        $this->authorizeBuild($request, $build);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:draft,saved,shared,ordered'],
        ]);

        return PcBuildResource::make($this->builder->update($build, $data));
    }

    public function destroy(Request $request, PcBuild $build): JsonResponse
    {
        $this->authorizeBuild($request, $build);
        $build->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function addItem(Request $request, PcBuild $build): PcBuildResource
    {
        $this->authorizeBuild($request, $build);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'component_type' => ['required', 'in:'.implode(',', PcBuildItem::COMPONENT_TYPES)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        return PcBuildResource::make($this->builder->addItem(
            $build,
            Product::query()->findOrFail($data['product_id']),
            $data['component_type'],
            $data['quantity'] ?? 1,
        ));
    }

    public function removeItem(Request $request, PcBuild $build, PcBuildItem $item): PcBuildResource
    {
        $this->authorizeBuild($request, $build);

        return PcBuildResource::make($this->builder->removeItem($build, $item));
    }

    public function compatibility(Request $request, PcBuild $build): PcCompatibilityResource
    {
        $this->authorizeBuild($request, $build);

        return PcCompatibilityResource::make($this->compatibility->validate($build));
    }

    public function recommendations(Request $request, PcBuild $build): JsonResponse
    {
        $this->authorizeBuild($request, $build);

        $recommendations = $this->recommendations->forBuild($build);
        $recommendations['suggested_products'] = ProductCardResource::collection($recommendations['suggested_products']);

        return response()->json(['data' => $recommendations]);
    }

    public function addToCart(
        Request $request,
        PcBuild $build,
        CartService $cartService,
        CartContextResolver $cartContext,
        CartPricingRefreshService $pricing,
        PromotionEngineService $promotions,
    ): CartResource {
        $this->authorizeBuild($request, $build);
        $cart = $cartContext->resolve($request);

        foreach ($build->items()->with('product')->get() as $item) {
            $cart = $cartService->add($cart, $item->product, $item->quantity);
        }

        $build->update(['status' => 'ordered']);

        return CartResource::make($pricing->refresh($promotions->applyAutomaticGifts($cart))->cart);
    }

    public function aiGenerate(Request $request, AiBuildGeneratorService $generator): PcBuildResource
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:1000']]);

        return PcBuildResource::make($generator->generate($data['query'], $this->user(), $this->sessionId($request)));
    }

    private function authorizeBuild(Request $request, PcBuild $build): void
    {
        $user = $this->user();
        abort_unless(
            $user ? $build->user_id === $user->id : $build->session_id === $this->sessionId($request),
            404,
        );
    }

    private function user()
    {
        return Auth::guard('sanctum')->user();
    }

    private function sessionId(Request $request): ?string
    {
        return $request->header('X-PC-Build-Session');
    }
}
