<?php

namespace App\Exceptions;

use App\Support\PersistentLogin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof TokenMismatchException) {
            try {
                // 419: refresh CSRF diam-diam + restore cookie. Jangan migrate session id
                // kecuali session memang corrupt (itu di cabang isSessionCorrupt).
                PersistentLogin::restoreFromRequest($request);
            } catch (Throwable) {
                //
            }

            if ($request->hasSession()) {
                $request->session()->regenerateToken();
            }

            if (PersistentLogin::isAjaxRequest($request)) {
                return response()->json([
                    'ok' => true,
                    'retry' => true,
                    'token' => csrf_token(),
                ], 419);
            }

            // Kembali ke halaman yang sedang dibuka, bukan login.
            if ($request->isMethod('GET')) {
                return redirect()->to(PersistentLogin::continueUrl($request));
            }

            return redirect()->to(PersistentLogin::continueUrl($request));
        }

        if ($e instanceof AuthenticationException) {
            $user = null;
            try {
                PersistentLogin::recreateSession($request);
                $user = PersistentLogin::restoreFromRequest($request);
            } catch (Throwable $ex) {
                if (PersistentLogin::isTransient($ex) && PersistentLogin::isAjaxRequest($request)) {
                    return PersistentLogin::retryJson($request);
                }
            }

            if ($user) {
                if (PersistentLogin::isAjaxRequest($request)) {
                    return PersistentLogin::retryJson($request);
                }

                return redirect()->to(PersistentLogin::continueUrl($request));
            }

            return PersistentLogin::unauthenticatedResponse($request);
        }

        // Session gagal dibaca / file corrupt → recreate + redirect ke halaman sebelumnya.
        if (
            PersistentLogin::isSessionCorrupt($e)
            && $request->isMethod('GET')
            && !$request->boolean('_sess_retry')
        ) {
            try {
                PersistentLogin::recreateSession($request);
                PersistentLogin::restoreFromRequest($request);
            } catch (Throwable) {
                //
            }

            return redirect()->to(PersistentLogin::withQuery(
                PersistentLogin::continueUrl($request),
                ['_sess_retry' => 1]
            ));
        }

        if (
            $request->isMethod('GET')
            && !$request->boolean('_retry')
            && $this->isTransientServerError($e)
        ) {
            return redirect()->to($request->fullUrlWithQuery(['_retry' => 1]));
        }

        return parent::render($request, $e);
    }

    private function isTransientServerError(Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() !== 500) {
            return false;
        }

        if (PersistentLogin::isTransient($e)) {
            return true;
        }

        $message = strtolower($e->getMessage().' '.$e->getPrevious()?->getMessage());

        return str_contains($message, 'has gone away')
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'unable to obtain lock')
            || (str_contains($message, 'session') && str_contains($message, 'lock'))
            || str_contains($message, 'sqlstate[40001]')
            || str_contains($message, 'sqlstate[hy000]');
    }
}
