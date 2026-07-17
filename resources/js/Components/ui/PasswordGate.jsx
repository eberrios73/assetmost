import { useState } from 'react';

/**
 * THE gate for sensitive screens: re-enter your own password to proceed.
 * Renders in place of the guarded content; calls onUnlocked() after the server
 * accepts. The server keeps its own window, so bypassing this component gets
 * you a 423, not the data.
 */
export default function PasswordGate({ endpoint = '/data/accounts-unlock', title = 'This area is protected', reason, onUnlocked }) {
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true); setError(null);
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const res = await fetch(endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify({ password }),
        });
        setBusy(false);
        if (res.ok) { onUnlocked(); return; }
        const body = await res.json().catch(() => ({}));
        setError(body?.errors?.password?.[0] || (res.status === 429 ? 'Too many tries — wait a minute.' : 'Could not unlock.'));
    };

    return (
        <div className="h-full flex items-center justify-center p-6">
            <form onSubmit={submit} className="w-full max-w-xs rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 text-center">
                <svg className="mx-auto mb-3 h-8 w-8 text-amber-500" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1a5 5 0 00-5 5v3H6a2 2 0 00-2 2v9a2 2 0 002 2h12a2 2 0 002-2v-9a2 2 0 00-2-2h-1V6a5 5 0 00-5-5zm3 8H9V6a3 3 0 016 0v3z" /></svg>
                <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-1">{title}</h3>
                {reason && <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">{reason}</p>}
                <input type="password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)}
                    placeholder="Your password"
                    className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500 mb-2" />
                {error && <p className="text-xs text-red-600 mb-2">{error}</p>}
                <button disabled={busy || !password}
                    className="w-full rounded-md bg-blue-600 px-3 py-2 text-sm text-white disabled:opacity-50">
                    {busy ? 'Checking…' : 'Unlock'}
                </button>
            </form>
        </div>
    );
}
