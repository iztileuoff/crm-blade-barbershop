<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictBarberAccess
{
    /**
     * Barbers may only access their own appointments and their earnings;
     * redirect them away from every other admin route. The redirect carries
     * a one-shot flag so the appointments page can explain why the barber
     * landed there instead of just silently swallowing the request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isBarber() && ! $request->routeIs('admin.appointments', 'admin.earnings')) {
            return redirect()->route('admin.appointments')->with('barberRestricted', true);
        }

        return $next($request);
    }
}
