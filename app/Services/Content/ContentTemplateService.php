<?php

namespace App\Services\Content;

use App\Models\ContentTemplate;

class ContentTemplateService
{
    public function starterTemplates(): array
    {
        return ['homepage', 'brand-page', 'category-page', 'black-friday', 'christmas', 'back-to-school', 'gaming-campaign', 'laptop-landing-page', 'printer-landing-page', 'b2b-landing-page'];
    }

    public function publishedTemplates()
    {
        return ContentTemplate::query()->orderBy('name')->get();
    }
}
