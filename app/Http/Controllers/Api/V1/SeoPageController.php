<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeoPageResource;
use App\Services\Content\SeoPageService;

class SeoPageController extends Controller
{
    public function __construct(private readonly SeoPageService $pages) {}

    public function show(string $slug): SeoPageResource
    {
        return SeoPageResource::make($this->pages->findPublished($slug));
    }
}
