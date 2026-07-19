<?php

namespace App\Http\Middleware;

use App\Support\Localization\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiLocale
{
    /**
     * Resolve an API response locale without persisting a user preference.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locales::resolveApiRequest($request);

        $request->setLocale($locale);
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        $vary = $response->getVary();

        foreach (['X-Locale', 'Accept-Language'] as $header) {
            $alreadyVaried = array_filter(
                $vary,
                static fn (string $existingHeader): bool => strcasecmp($existingHeader, $header) === 0,
            );

            if ($alreadyVaried === []) {
                $vary[] = $header;
            }
        }

        $response->setVary($vary);

        return $response;
    }
}
