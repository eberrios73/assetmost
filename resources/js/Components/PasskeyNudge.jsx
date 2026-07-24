import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { registerPasskey, passkeysSupported } from '@/lib/webauthn';

/**
 * The enrollment offer, made where it belongs: right after a password login.
 * Shows only when this account has no passkeys, the browser can make one,
 * and this device hasn't said "later". One tap enrolls; Later dismisses for
 * good on this device — the profile screen remains the manual path.
 */
export default function PasskeyNudge() {
    const { auth } = usePage().props;
    const dismissKey = `assetmost:passkey-nudge:${auth?.user?.id}`;
    const [show, setShow] = useState(false);
    const [done, setDone] = useState(false);

    useEffect(() => {
        if (!auth?.user || !passkeysSupported() || localStorage.getItem(dismissKey)) return;
        fetch('/webauthn/credentials', { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((keys) => { if (Array.isArray(keys) && keys.length === 0) setShow(true); })
            .catch(() => {});
    }, []);

    if (!show) return null;
    const later = () => { localStorage.setItem(dismissKey, '1'); setShow(false); };
    const enroll = async () => {
        try { await registerPasskey('This device'); setDone(true); setTimeout(() => setShow(false), 2500); }
        catch { /* they cancelled the sheet; keep the banner */ }
    };

    return (
        <div className="flex items-center gap-3 border-b border-blue-100 dark:border-blue-900 bg-blue-50 dark:bg-blue-500/10 px-4 py-2 text-sm">
            {done ? (
                <span className="text-green-700 dark:text-green-400">✓ Passkey saved — next time, just tap.</span>
            ) : (
                <>
                    <span className="text-gray-700 dark:text-gray-200">🔑 Skip the password next time — set up a passkey for this device.</span>
                    <button onClick={enroll} className="rounded-md bg-blue-600 px-3 py-1 text-xs font-semibold text-white hover:bg-blue-700">Set it up</button>
                    <button onClick={later} className="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Later</button>
                </>
            )}
        </div>
    );
}
