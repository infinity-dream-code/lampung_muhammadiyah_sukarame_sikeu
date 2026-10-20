<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestorePersistentLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->routeIs('logout')) {
            try {
                PersistentLogin::restoreFromRequest($request);
            } catch (\Throwable $e) {
                if (PersistentLogin::isSessionCorrupt($e) && $request->isMethod('GET') && !$request->boolean('_sess_retry')) {
                    PersistentLogin::recreateSession($request);
                    try {
                        PersistentLogin::restoreFromRequest($request);
                    } catch (\Throwable) {
                        //
                    }

                    return redirect()->to($request->fullUrlWithQuery(['_sess_retry' => 1]));
                }

                if (!PersistentLogin::isTransient($e)) {
                    throw $e;
                }
                // Biarkan RetryTransientGet / Handler mengulang GET.
            }
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('CDN-Cache-Control', 'no-store');

        return $response;
    }
}
