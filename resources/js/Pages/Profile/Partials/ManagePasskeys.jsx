import { useEffect, useState } from 'react';
import { registerPasskey, passkeysSupported } from '@/lib/webauthn';

/**
 * Enroll and revoke passkeys. The marquee: the whole app opens with a tap —
 * this is where the tap gets created. Password stays as the fallback.
 */
export default function ManagePasskeys({ className = '' }) {
    const [keys, setKeys] = useState([]);
    const [name, setName] = useState('');
    const [msg, setMsg] = useState(null);
    const load = () => fetch('/webauthn/credentials', { headers: { Accept: 'application/json' } })
        .then((r) => r.json()).then(setKeys);
    useEffect(() => { load(); }, []);

    const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const add = async () => {
        setMsg(null);
        try { await registerPasskey(name || undefined); setName(''); setMsg('Passkey added.'); load(); }
        catch { setMsg('Could not add the passkey.'); }
    };
    const revoke = async (id) => {
        await fetch(`/webauthn/credentials/${id}`, { method: 'DELETE', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } });
        load();
    };

    if (!passkeysSupported()) return null;
    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">Passkeys</h2>
                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Sign in — and unlock the accounts registry — with a tap instead of a password.
                    Enroll each device you use: this Mac, your phone, a security key.
                </p>
            </header>
            <div className="mt-4 space-y-2">
                {keys.map((k) => (
                    <div key={k.id} className="flex items-center gap-3 text-sm">
                        <span className="text-gray-800 dark:text-gray-200">🔑 {k.name}</span>
                        <span className="text-xs text-gray-400">added {k.created_at}{k.last_used_at ? ` · last used ${k.last_used_at}` : ''}</span>
                        <button onClick={() => revoke(k.id)} className="ml-auto text-xs text-gray-400 hover:text-red-600">revoke</button>
                    </div>
                ))}
                {!keys.length && <p className="text-sm text-gray-400">No passkeys yet.</p>}
            </div>
            <div className="mt-4 flex items-center gap-2">
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name it (e.g. MacBook Touch ID)"
                    className="w-64 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-sm" />
                <button onClick={add} className="rounded-md bg-gray-800 dark:bg-gray-200 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white dark:text-gray-800">
                    Add this device
                </button>
                {msg && <span className="text-xs text-gray-500">{msg}</span>}
            </div>
        </section>
    );
}
