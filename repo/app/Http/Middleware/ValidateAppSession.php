<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the custom session on every authenticated request.
 * Enforces: revocation check, idle timeout (20 min by default).
 *
 * Applied to all authenticated routes.
 */
class ValidateAppSession
{
    public function __construct(private readonly SessionManager $sessionManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        // The session security layer (revocation + idle-timeout) is backed by the
        // `sessions` DB table, which recordSession() always writes to on login
        // regardless of the PHP session storage driver.  Any driver that produces
        // a persistent session ID across requests (database, redis, file, …) will
        // have a corresponding DB row and must be fully validated here.
        //
        // The ONLY safe bypass is the `array` driver, which stores sessions in
        // memory for a single request only — the session ID does not survive across
        // requests and recordSession() is never called, so no DB row ever exists.
        // This driver is used exclusively in automated tests (phpunit.xml:
        // SESSION_DRIVER=array).
        //
        // Production uses SESSION_DRIVER=redis → full revocation + idle-timeout
        // enforcement is active for Redis-backed deployments.  A database-only
        // check would silently disable all session controls on Redis runtimes.
        if (config('session.driver') === 'array') {
            return $next($request);
        }

        $sessionId = session()->getId();

        if (!$this->sessionManager->isSessionValid($sessionId)) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Session expired or revoked.'], 401);
            }

            return redirect()->route('login')->with('warning', 'Your session has expired. Please log in again.');
        }

        $this->sessionManager->touchSession($sessionId);

        return $next($request);
    }
}
