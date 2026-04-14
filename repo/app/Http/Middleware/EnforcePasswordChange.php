<?php

namespace App\Http\Middleware;

use App\Services\Auth\PasswordChangeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intercepts every authenticated request and forces the user to the
 * password-change screen when either:
 *   - must_change_password = true (set by admin or import)
 *   - rotation window has expired (password_changed_at + rotation_days in past)
 *
 * The /password/change route is intentionally placed OUTSIDE this middleware
 * group in web.php so this middleware never creates an infinite redirect.
 *
 * API requests receive 403 with a machine-readable body instead of a redirect.
 */
class EnforcePasswordChange
{
    public function __construct(private readonly PasswordChangeService $passwordChangeService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($this->passwordChangeService->mustChange($user)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message'  => 'Password change required before proceeding.',
                    'redirect' => route('password.change'),
                ], 403);
            }

            return redirect()->route('password.change')
                ->with('warning', 'You must change your password before continuing.');
        }

        return $next($request);
    }
}
