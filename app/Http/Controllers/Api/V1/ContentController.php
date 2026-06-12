<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentPageResource;
use App\Http\Resources\ContentTemplateResource;
use App\Services\Content\BlockRegistry;
use App\Services\Content\ContentPageService;
use App\Services\Content\ContentTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContentController extends Controller
{
    public function __construct(
        private readonly ContentPageService $pages,
        private readonly ContentTemplateService $templates,
        private readonly BlockRegistry $blocks,
    ) {}

    public function homepage(Request $request): ContentPageResource
    {
        $page = $this->pages->homepageOrFail($request);

        return ContentPageResource::make([
            'page' => $page,
            'blocks' => $this->pages->render($page, $request),
        ]);
    }

    public function show(string $slug, Request $request): ContentPageResource
    {
        $page = $this->pages->findPublished($slug, $request);

        return ContentPageResource::make([
            'page' => $page,
            'blocks' => $this->pages->render($page, $request),
        ]);
    }

    public function templates(): AnonymousResourceCollection
    {
        return ContentTemplateResource::collection($this->templates->publishedTemplates());
    }

    public function blockTypes(): array
    {
        return ['data' => $this->blocks->all()];
    }
}
