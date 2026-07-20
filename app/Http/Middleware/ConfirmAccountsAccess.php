<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * The Accounts registry is a map of every admin credential in the realm — the list
 * itself is sensitive, not just the passwords on it. So reaching it requires
 * re-entering YOUR password, recently. The UI prompts on every visit; this window
 * is the server-side backstop so a stolen session can't quietly enumerate it.
 */
class ConfirmAccountsAccess
{
    public const WINDOW_SECONDS = 900;   // 15 min of work per unlock

    public function handle(Request $request, Closure $next)
    {
        $at = $request->session()->get('accounts_confirmed_at', 0);
        if (time() - $at > self::WINDOW_SECONDS) {
            return response()->json(['locked' => true], 423);
        }

        return $next($request);
    }
}
