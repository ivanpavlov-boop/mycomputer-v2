<?php

namespace App\Services\Content;

use App\Models\Redirect;
use Illuminate\Http\Request;

class RedirectService
{
    public function find(Request $request): ?Redirect
    {
        $path = '/'.ltrim($request->path(), '/');

        return Redirect::query()
            ->active()
            ->where('source_url', $path)
            ->first();
    }

    public function assertSafeTarget(string $targetUrl): void
    {
        abort_if(str_starts_with($targetUrl, '//'), 422, 'Redirect target is not allowed.');

        $host = parse_url($targetUrl, PHP_URL_HOST);
        abort_if($host !== null && ! in_array($host, ['mycomputer.bg', 'www.mycomputer.bg'], true), 422, 'Redirect target is not allowed.');
    }
}
