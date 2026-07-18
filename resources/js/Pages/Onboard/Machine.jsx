import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import AddButton from '@/Components/ui/AddButton';

const api = async (url, body) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const res = await fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
        body: JSON.stringify(body),
    });
    if (!res.ok) throw await res.json().catch(() => ({}));
    return res.json();
};

/**
 * Opened ON the machine being set up (the login page was the gate). Pick the
 * runbook, hit generate: the tag is issued, the checklist project exists, and
 * the script below does the machine's share of the work — reporting each step
 * back so the checklist ticks itself.
 */
export default function Machine({ variants = [], types = [] }) {
    const [variant, setVariant] = useState(variants[0]?.variant ?? '');
    const [typeId, setTypeId] = useState(types.find((t) => t.code === 'WS')?.id ?? types[0]?.id ?? '');
    const [form, setForm] = useState({ brand: '', model: '', serial_num: '' });
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [copied, setCopied] = useState(false);

    const generate = async () => {
        setBusy(true); setError(null);
        try {
            setResult(await api('/onboard/generate', { variant, device_type_id: typeId, ...form }));
        } catch (e) {
            setError(Object.values(e?.errors || {}).flat()[0] || e?.message || 'Could not generate.');
        }
        setBusy(false);
    };

    const copy = async () => {
        await navigator.clipboard.writeText(result.script);
        setCopied(true); setTimeout(() => setCopied(false), 1500);
    };

    const detail = (
        <div className="max-w-3xl">
            <h1 className="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-1">Onboard this machine</h1>
            <p className="text-sm text-gray-500 dark:text-gray-400 mb-5">
                Issues the asset tag, creates the setup checklist, and generates a script that does this
                machine's share — hostname, domain join, BitLocker with the key escrowed straight to the
                registry — ticking the checklist as it goes. Safe to re-run after the join reboot.
            </p>

            {!result ? (
                <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <label className="block">
                            <span className="block text-xs uppercase tracking-wide text-gray-400 mb-1">Runbook</span>
                            <select value={variant} onChange={(e) => setVariant(e.target.value)}
                                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm">
                                {variants.map((v) => <option key={v.variant} value={v.variant}>{v.name}</option>)}
                            </select>
                        </label>
                        <label className="block">
                            <span className="block text-xs uppercase tracking-wide text-gray-400 mb-1">Device type</span>
                            <select value={typeId} onChange={(e) => setTypeId(Number(e.target.value))}
                                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm">
                                {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </select>
                        </label>
                        {['brand', 'model', 'serial_num'].map((k) => (
                            <label key={k} className="block">
                                <span className="block text-xs uppercase tracking-wide text-gray-400 mb-1">{k === 'serial_num' ? 'Serial #' : k[0].toUpperCase() + k.slice(1)}</span>
                                <input value={form[k]} onChange={(e) => setForm((f) => ({ ...f, [k]: e.target.value }))}
                                    className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm" />
                            </label>
                        ))}
                    </div>
                    {error && <p className="text-sm text-red-600">{error}</p>}
                    <AddButton label={busy ? 'Generating…' : 'Issue tag & generate script'} onClick={busy ? () => {} : generate} />
                </div>
            ) : (
                <div className="space-y-3">
                    <div className="rounded-lg border border-green-200 dark:border-green-900 bg-green-50 dark:bg-green-500/10 p-4">
                        <p className="text-sm font-medium text-green-800 dark:text-green-300">
                            This machine is <span className="font-mono">{result.asset_tag}</span> — checklist project created.
                        </p>
                        <p className="text-xs text-green-700 dark:text-green-400 mt-1">
                            Paste the script into an <strong>elevated PowerShell</strong>. It renames, joins the domain
                            (asks for the join account), reboots — <strong>run it again after</strong> — then BitLocker + key escrow + updates.
                        </p>
                    </div>
                    <div className="relative">
                        <button onClick={copy}
                            className="absolute right-2 top-2 rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700">
                            {copied ? 'Copied ✓' : 'Copy script'}
                        </button>
                        <pre className="max-h-[50vh] overflow-auto rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-950 p-4 text-xs leading-relaxed text-green-300">
{result.script}
                        </pre>
                    </div>
                    <div className="flex gap-2">
                        <a href="/tasks" className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">Open the checklist</a>
                        <button onClick={() => setResult(null)} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">Onboard another machine</button>
                    </div>
                </div>
            )}
        </div>
    );

    return (
        <>
            <Head title="Onboard machine" />
            <AppShell active="assets" detail={detail} footer={<span>Machine bootstrap</span>} />
        </>
    );
}
