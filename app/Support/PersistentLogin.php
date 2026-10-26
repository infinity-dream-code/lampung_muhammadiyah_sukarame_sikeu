<?php

namespace App\Support;

use App\Models\CyberKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PersistentLogin
{
    public const COOKIE = 'mit_muhammadiyah_sukarame';

    public static function lifetimeMinutes(): int
    {
        $minutes = (int) config('session.lifetime', 5256000);

        return $minutes > 0 ? $minutes : 5256000;
    }

    /**
     * Restore auth dari cookie/session tanpa session->migrate().
     * Auth::login() sengaja tidak dipakai di sini karena migrate mengganti
     * session id + CSRF dan memicu 419/logout tiba-tiba.
     */
    public static function bind(?CyberKey $user): void
    {
        if (!$user) {
            return;
        }

        $guard = Auth::guard();
        $id = $user->getAuthIdentifier();

        if (session()->isStarted() && method_exists($guard, 'getName')) {
            session()->put($guard->getName(), $id);
        }

        $guard->setUser($user);
    }

    public static function queue(?CyberKey $user, ?Request $request = null): void
    {
        if (!$user) {
            return;
        }

        $request ??= request();
        $value = self::encodePayload($user, $request);

        Cookie::queue(cookie(
            self::COOKIE,
            $value,
            self::lifetimeMinutes(),
            config('session.path', '/'),
            config('session.domain'),
            self::secure(),
            true,
            false,
            config('session.same_site', 'lax')
        ));

        if ($request instanceof Request) {
            $request->cookies->set(self::COOKIE, $value);
        }
    }

    public static function forget(): void
    {
        Cookie::queue(cookie(
            self::COOKIE,
            '',
            -2628000,
            config('session.path', '/'),
            config('session.domain'),
            self::secure(),
            true,
            false,
            config('session.same_site', 'lax')
        ));
    }

    public static function restoreFromRequest(Request $request): ?CyberKey
    {
        $current = Auth::user();
        if ($current instanceof CyberKey) {
            self::queue($current);

            return $current;
        }

        $user = self::userFromRequest($request);
        if ($user) {
            self::bind($user);
            self::queue($user);
        }

        return $user;
    }

    public static function hasCookie(Request $request): bool
    {
        return self::rawCookieValue($request) !== null;
    }

    public static function unauthenticatedResponse(Request $request): Response
    {
        self::rememberContinueUrl($request);

        $user = null;
        try {
            self::recreateSession($request);
            $user = self::restoreFromRequest($request);
        } catch (Throwable $e) {
            if (self::isTransient($e) && self::isAjaxRequest($request)) {
                return self::retryJson($request);
            }
        }

        $target = self::continueUrl($request);

        // Cookie / user masih ada: jangan ke login. Recreate lalu kembali ke halaman sebelumnya.
        if ($user || self::hasCookie($request)) {
            if (self::isAjaxRequest($request)) {
                return self::retryJson($request, $target);
            }

            if ($user) {
                return redirect()->to($target);
            }

            if (!$request->boolean('_sess_retry')) {
                return redirect()->to(self::withQuery($target, ['_sess_retry' => 1]));
            }
        }

        if (self::isAjaxRequest($request)) {
            return self::retryJson($request, $target);
        }

        return redirect()->guest(route('login'));
    }

    public static function retryJson(Request $request, ?string $redirect = null, int $status = 401): Response
    {
        return response()->json([
            'ok' => true,
            'retry' => true,
            'authenticated' => (bool) Auth::user(),
            'token' => csrf_token(),
            'redirect' => $redirect ?: self::continueUrl($request),
        ], $status);
    }

    public static function continueUrl(Request $request): string
    {
        $candidates = [
            self::cleanUrl($request->fullUrl()),
            $request->hasSession() ? $request->session()->get('url.intended') : null,
            $request->headers->get('Referer'),
            url()->previous(),
            self::pathFromCookie($request),
            url('/admin'),
        ];

        foreach ($candidates as $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            $absolute = self::toAbsoluteUrl($url, $request);
            if (!self::isSafeInternalUrl($absolute, $request)) {
                continue;
            }

            $absolute = self::cleanUrl($absolute);
            if (self::isAuthUrl($absolute) || self::isNonPageUrl($absolute)) {
                continue;
            }

            return $absolute;
        }

        return url('/admin');
    }

    public static function rememberContinueUrl(Request $request): void
    {
        if (
            !$request->hasSession()
            || !$request->isMethod('GET')
            || self::isAuthUrl($request->fullUrl())
            || self::isNonPageUrl($request->fullUrl())
            || self::isAjaxRequest($request)
        ) {
            return;
        }

        $url = self::cleanUrl($request->fullUrl());
        $request->session()->put('url.intended', $url);
        $request->session()->put('_last_visited_url', $url);
    }

    public static function isAuthUrl(?string $url): bool
    {
        if (!is_string($url) || $url === '') {
            return true;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === ''
            || $path === 'login'
            || $path === 'logout'
            || $path === 'register'
            || str_starts_with($path, 'password')
            || str_starts_with($path, 'email/');
    }

    /**
     * Endpoint JSON/DataTables/export — bukan halaman yang boleh jadi tujuan redirect.
     */
    public static function isNonPageUrl(?string $url): bool
    {
        if (!is_string($url) || $url === '') {
            return true;
        }

        $path = strtolower(trim((string) parse_url($url, PHP_URL_PATH), '/'));
        if ($path === '') {
            return false;
        }

        foreach ([
            'get-data',
            'get-column',
            'keep-alive',
            'reload-captcha',
            'reload-math-captcha',
            'get-siswa',
            'get-tagihan',
            'get-detail-tagihan',
            'get-detail-taighan',
            'get-logo',
            'get-saldo',
        ] as $needle) {
            if ($path === $needle || str_ends_with($path, '/'.$needle)) {
                return true;
            }
        }

        return false;
    }

    public static function redirectIfBareDataEndpoint(Request $request): ?Response
    {
        if (!$request->isMethod('GET') || self::isAjaxRequest($request) || $request->has('draw')) {
            return null;
        }

        $path = trim($request->path(), '/');
        if ($path === '' || !self::isNonPageUrl('/'.$path)) {
            return null;
        }

        if (!str_ends_with($path, '/get-data') && !str_ends_with($path, '/get-column')) {
            return null;
        }

        $parent = preg_replace('#/(get-data|get-column)$#', '', '/'.$path);
        if (!is_string($parent) || $parent === '' || $parent === '/') {
            $parent = '/admin';
        }

        return redirect()->to($parent);
    }

    /**
     * Session file/cookie rusak atau gagal dibaca: buat session baru,
     * restore auth dari cookie persistent, tetap di halaman yang sama.
     * Jangan Auth::login() (migrate CSRF) dan jangan logout.
     */
    public static function recreateSession(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        try {
            $session = $request->session();
            $session->migrate(true);
            $session->regenerateToken();
            $session->put('_session_recreated_at', now()->timestamp);
        } catch (Throwable) {
            try {
                $request->session()->regenerateToken();
            } catch (Throwable) {
                //
            }
        }
    }

    public static function isSessionCorrupt(Throwable $e): bool
    {
        $haystack = strtolower($e::class.' '.$e->getMessage());

        foreach ([
            'unable to retrieve the session',
            'session store not set on request',
            'failed to read session',
            'session data corrupted',
            'session has already been started',
            'unserialize',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isAjaxRequest(Request $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || $request->wantsJson()
            || $request->header('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string) $request->header('Accept'), 'application/json');
    }

    public static function userFromRequest(Request $request): ?CyberKey
    {
        $raw = self::rawCookieValue($request);
        if ($raw === null) {
            return null;
        }

        try {
            $data = self::decodePayload($raw);
            if (!is_array($data) || blank($data['id'] ?? null)) {
                return null;
            }

            $user = CyberKey::query()->where('urut', $data['id'])->first();
            if (!$user) {
                return null;
            }

            $cookieUser = trim((string) ($data['u'] ?? ''));
            if ($cookieUser !== '' && strcasecmp($cookieUser, (string) $user->users) !== 0) {
                return null;
            }

            return $user;
        } catch (Throwable $e) {
            // Jangan anggap logout jika DB/session transient — biarkan caller retry.
            if (self::isTransient($e)) {
                throw $e;
            }

            return null;
        }
    }

    public static function isTransient(Throwable $e): bool
    {
        $haystack = strtolower($e::class.' '.$e->getMessage());

        foreach ([
            'deadlock',
            'has gone away',
            'lost connection',
            'lock wait timeout',
            'unable to obtain lock',
            'unable to create lockable file',
            'resource temporarily unavailable',
            'serialization failure',
            'try restarting transaction',
            'no active transaction',
            'being used by another process',
            'failed to open stream',
            'permission denied',
            'unable to retrieve the session',
            'session store not set on request',
            'connection refused',
            'too many connections',
            'packets out of order',
            'sqlstate[40001]',
            'sqlstate[hy000] [2002]',
            'sqlstate[hy000] [2006]',
            'sqlstate[hy000] [1045]',
            'sqlstate[hy000] [2002]',
            'sqlstate[hy000]: general error: 1205',
            'sqlstate[hy000]: general error: 2006',
            'sqlstate[hy000]: general error: 2013',
            'please retry',
            'access denied for user',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        $previous = $e->getPrevious();

        return $previous instanceof Throwable && self::isTransient($previous);
    }

    private static function rawCookieValue(Request $request): ?string
    {
        foreach ([
            $request->cookie(self::COOKIE),
            $request->cookies->get(self::COOKIE),
            $_COOKIE[self::COOKIE] ?? null,
        ] as $raw) {
            if (is_string($raw) && trim($raw) !== '') {
                return trim($raw);
            }
        }

        return null;
    }

    private static function encodePayload(CyberKey $user, ?Request $request = null): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode([
            'id' => $user->getAuthIdentifier(),
            'u' => (string) $user->users,
            'p' => self::safePath($request),
        ], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');

        return $encoded.'.'.self::signature($encoded);
    }

    private static function safePath(?Request $request): ?string
    {
        if (!$request instanceof Request) {
            return null;
        }

        if (
            !$request->isMethod('GET')
            || self::isAuthUrl($request->fullUrl())
            || self::isNonPageUrl($request->fullUrl())
            || self::isAjaxRequest($request)
            || $request->routeIs('admin.keep-alive')
        ) {
            $fromCookie = self::pathFromCookie($request);
            if ($fromCookie) {
                $path = parse_url($fromCookie, PHP_URL_PATH);
                $query = parse_url($fromCookie, PHP_URL_QUERY);

                return $path ? ($query ? $path.'?'.$query : $path) : null;
            }

            return null;
        }

        $uri = $request->getRequestUri();
        if (!is_string($uri) || $uri === '' || strlen($uri) > 400) {
            return $request->getPathInfo() ?: '/admin';
        }

        return self::cleanUrl($uri);
    }

    private static function pathFromCookie(Request $request): ?string
    {
        $raw = self::rawCookieValue($request);
        if ($raw === null) {
            return null;
        }

        try {
            $data = self::decodePayload($raw);
            $path = is_array($data) ? ($data['p'] ?? null) : null;

            return is_string($path) && $path !== '' ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function cleanUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        unset($query['_sess_retry'], $query['_auth_retry'], $query['_retry'], $query['_ts']);

        $path = $parts['path'] ?? '/';
        if (!isset($parts['host'])) {
            return $query ? $path.'?'.http_build_query($query) : $path;
        }

        $absolute = ($parts['scheme'] ?? request()?->getScheme() ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path;

        return $query ? $absolute.'?'.http_build_query($query) : $absolute;
    }

    public static function withQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($params);
    }

    private static function toAbsoluteUrl(string $url, Request $request): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $request->getSchemeAndHttpHost().(str_starts_with($url, '/') ? $url : '/'.$url);
    }

    private static function isSafeInternalUrl(string $url, Request $request): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && strcasecmp($host, $request->getHost()) !== 0) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        return is_string($path) && str_starts_with($path, '/');
    }

    private static function decodePayload(string $raw): ?array
    {
        $candidates = [$raw, urldecode($raw)];

        foreach (self::decryptLegacy($raw) as $legacy) {
            $candidates[] = $legacy;
        }

        foreach (array_unique(array_filter($candidates, 'is_string')) as $candidate) {
            $parsed = self::parseCandidate($candidate);
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    private static function parseCandidate(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '.')) {
            [$encoded, $signature] = explode('.', $raw, 2);
            if ($encoded !== '' && $signature !== '' && hash_equals(self::signature($encoded), $signature)) {
                $json = base64_decode(strtr($encoded, '-_', '+/'), true);
                $data = is_string($json) ? json_decode($json, true) : null;

                return is_array($data) ? $data : null;
            }
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @return list<string> */
    private static function decryptLegacy(string $raw): array
    {
        $found = [];

        try {
            $found[] = Crypt::decryptString($raw);
        } catch (Throwable $e) {
            //
        }

        try {
            $decrypted = Crypt::decrypt($raw, false);
            if (is_string($decrypted)) {
                $found[] = $decrypted;
            }
        } catch (Throwable $e) {
            //
        }

        return $found;
    }

    private static function signature(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private static function secure(): bool
    {
        $configured = config('session.secure');
        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) request()?->isSecure();
    }
}
