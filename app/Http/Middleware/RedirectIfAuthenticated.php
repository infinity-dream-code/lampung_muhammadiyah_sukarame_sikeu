<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $intended = $request->session()->pull('url.intended');
                if (
                    is_string($intended)
                    && !PersistentLogin::isAuthUrl($intended)
                    && !PersistentLogin::isNonPageUrl($intended)
                ) {
                    return redirect()->to($intended);
                }

                return redirect()->to(PersistentLogin::continueUrl($request));
            }
        }

        return $next($request);
    }
}
