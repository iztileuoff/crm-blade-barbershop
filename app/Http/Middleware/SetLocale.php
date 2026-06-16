<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the visitor's chosen locale (stored in the session) to the
     * application and Carbon for the duration of the request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (is_string($locale) && array_key_exists($locale, config('app.supported_locales', []))) {
            app()->setLocale($locale);
        }

        // Carbon has no Karakalpak locale; Uzbek is the closest Turkic fallback.
        Carbon::setLocale(app()->getLocale() === 'kaa' ? 'uz' : app()->getLocale());

        return $next($request);
    }
}
