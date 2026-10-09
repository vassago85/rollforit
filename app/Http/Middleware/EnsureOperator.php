<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatekeeper for the operator dashboard. Must run after `auth` so Auth::user() is populated.
 */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || ! (bool) ($user->is_operator ?? false)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('op.login')->withErrors([
                'email' => 'That account does not have operator access.',
            ]);
        }

        return $next($request);
    }
}
