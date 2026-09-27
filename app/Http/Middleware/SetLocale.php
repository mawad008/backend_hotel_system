<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = config('app.available_locales', ['en']);

        $locale = $request->header('X-Locale')
            ?? $request->query('lang')
            ?? $request->getPreferredLanguage($available);

        if ($locale && in_array($locale, $available, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
