<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Content\SitemapService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function sitemap(SitemapService $sitemap): Response
    {
        return response($sitemap->xml(), 200)->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        return response("User-agent: *\nAllow: /\nSitemap: ".url('/sitemap.xml')."\n", 200)
            ->header('Content-Type', 'text/plain');
    }
}
