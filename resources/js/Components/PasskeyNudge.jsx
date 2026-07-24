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
    // Session-scoped: Later quiets it until the next sign-in. The only
    // permanent dismissal is enrolling — that's the point of the ask.
    const dismissKey = `assetmost:passkey-nudge:${auth?.user?.id}`;
    const [show, setShow] = useState(false);
    const [done, setDone] = useState(false);

    useEffect(() => {
        if (!auth?.user || !passkeysSupported() || sessionStorage.getItem(dismissKey)) return;
        fetch('/webauthn/credentials', { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((keys) => { if (Array.isArray(keys) && keys.length === 0) setShow(true); })
            .catch(() => {});
    }, []);

    if (!show) return null;
    const later = () => { sessionStorage.setItem(dismissKey, '1'); setShow(false); };
    const enroll = async () => {
        try { await registerPasskey('This device'); setDone(true); setTimeout(() => setShow(false), 2500); }
        catch { /* they cancelled the sheet; keep the banner */ }
    };

    return (
        <div className="fixed inset-0 z-[95] flex items-center justify-center bg-black/40 backdrop-blur-[2px]">
            <div className="w-[420px] max-w-[90vw] rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-6 shadow-2xl text-center">
                {done ? (
                    <p className="text-green-700 dark:text-green-400 text-sm font-medium">✓ Passkey saved — next time, just tap.</p>
                ) : (
                    <>
                        <img src="/passkey-192.png" alt="" className="mx-auto mb-3 h-16 w-auto" />
                        <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Skip the password next time</h2>
                        <p className="mt-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Set up a passkey and this device signs you in with a tap — Touch ID, Face ID, or your security key.
                            Your password keeps working as the fallback.
                        </p>
                        <div className="mt-5 flex justify-center gap-3">
                            <button onClick={enroll} className="rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700">Set it up</button>
                            <button onClick={later} className="rounded-md border border-gray-200 dark:border-gray-700 px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Later</button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
