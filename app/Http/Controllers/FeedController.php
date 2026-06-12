<?php

namespace App\Http\Controllers;

use App\Services\Marketing\FacebookCatalogService;
use App\Services\Marketing\MerchantFeedService;

class FeedController extends Controller
{
    public function googleMerchant(MerchantFeedService $feed)
    {
        return response($feed->cachedXml(), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function facebookCatalog(FacebookCatalogService $feed)
    {
        return response($feed->cachedXml(), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
