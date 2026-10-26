<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PersistentLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KeepAliveController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            PersistentLogin::restoreFromRequest($request);
        } catch (\Throwable $e) {
            // Keep-alive tetap mengembalikan token baru meski restore transient gagal.
        }

        $user = Auth::user();
        if (!$user && PersistentLogin::hasCookie($request)) {
            try {
                PersistentLogin::recreateSession($request);
                $user = PersistentLogin::restoreFromRequest($request);
            } catch (\Throwable) {
                $user = Auth::user();
            }
        }

        if ($user) {
            PersistentLogin::queue($user, $request);
        }

        if ($request->hasSession()) {
            // Pastikan session tetap hidup tanpa mengganti CSRF tiap ping.
            $request->session()->put('_keepalive_at', now()->timestamp);
        }

        return response()->json([
            'ok' => true,
            'authenticated' => (bool) $user,
            'token' => csrf_token(),
            'redirect' => $user ? PersistentLogin::continueUrl($request) : null,
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('CDN-Cache-Control', 'no-store');
    }
}
