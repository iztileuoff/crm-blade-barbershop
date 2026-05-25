<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictBarberAccess
{
    /**
     * Barbers may only access their own appointments; redirect them away
     * from every other admin route.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isBarber() && ! $request->routeIs('admin.appointments')) {
            return redirect()->route('admin.appointments');
        }

        return $next($request);
    }
}
