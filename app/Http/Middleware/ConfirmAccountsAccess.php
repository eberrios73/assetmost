<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Sudo mode for the sensitive surfaces: the Accounts registry (the list itself is a
 * map of the realm's admin credentials) and every password reveal. Reaching them
 * requires re-entering YOUR password, recently — proof of presence, not just a live
 * session. The UI raises the gate on 423; this window is the server-side backstop so
 * a stolen session can't enumerate the registry or drain secrets one eye-click at a time.
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
