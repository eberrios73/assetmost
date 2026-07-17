import { useEffect, useState } from 'react';
import { PlusIcon, TrashIcon } from '@/Components/Icons';

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const api = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then(async (r) => ({ ok: r.ok, status: r.status, body: r.status === 204 ? {} : await r.json().catch(() => ({})) }));

/**
 * A vendor's product catalog — Adobe ▸ Creative Cloud, Firefly, Substance.
 *
 * Catalog only: licences are NOT nested here. A vendor can list hundreds of SKUs, so
 * nesting what you own inside what they sell makes an unusable tree. Licences live on
 * their own screen, filtered by product.
 */
export default function VendorProducts({ vendorId }) {
    const [rows, setRows] = useState(null);
    const [adding, setAdding] = useState(false);
    const [name, setName] = useState('');
    const [sku, setSku] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const load = () => api(`/data/vendors/${vendorId}/products`).then((r) => setRows(r.body));
    useEffect(() => { setRows(null); load(); }, [vendorId]);

    const add = async () => {
        if (!name.trim()) return;
        setBusy(true); setError('');
        const r = await api(`/data/vendors/${vendorId}/products`, 'POST', { name: name.trim(), sku: sku.trim() || null });
        setBusy(false);
        if (!r.ok) { setError(r.body?.errors?.name?.[0] || 'Could not add.'); return; }
        setName(''); setSku(''); setAdding(false); load();
    };

    const rename = async (p, newName) => {
        if (!newName.trim() || newName === p.name) return;
        const r = await api(`/data/products/${p.id}`, 'PATCH', { name: newName.trim() });
        if (!r.ok) setError(r.body?.errors?.name?.[0] || 'Could not rename.');
        load();
    };

    const toggleActive = async (p) => { await api(`/data/products/${p.id}`, 'PATCH', { active: !p.active }); load(); };

    const remove = async (p) => {
        if (!confirm(`Delete product "${p.name}"?`)) return;
        const r = await api(`/data/products/${p.id}`, 'DELETE');
        // Refused while licences point at it — a stale catalog row is cheaper than an
        // orphaned licence.
        if (!r.ok) { setError(r.body?.errors?.name?.[0] || 'Could not delete.'); return; }
        load();
    };

    if (rows === null) return <p className="text-sm text-gray-400 py-6">Loading…</p>;

    const dash = <span className="text-gray-300">—</span>;
    return (
        <div>
            <div className="flex items-center justify-between mb-3">
                <span className="text-xs text-gray-400">{rows.length} product{rows.length === 1 ? '' : 's'}</span>
                {!adding && (
                    <button onClick={() => { setAdding(true); setError(''); }}
                        className="flex items-center gap-1 rounded-md border border-gray-200 dark:border-gray-700 px-2.5 py-1 text-xs text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600">
                        <PlusIcon /> Add product
                    </button>
                )}
            </div>

            {adding && (
                <div className="flex items-center gap-2 mb-3 rounded-md border border-blue-200 dark:border-blue-500/30 bg-blue-50/40 dark:bg-blue-500/10 p-2">
                    <input autoFocus value={name} onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') add(); if (e.key === 'Escape') setAdding(false); }}
                        placeholder="Product name — e.g. Firefly"
                        className="flex-1 rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                    <input value={sku} onChange={(e) => setSku(e.target.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') add(); if (e.key === 'Escape') setAdding(false); }}
                        placeholder="SKU (optional)"
                        className="w-40 rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                    <button onClick={add} disabled={busy}
                        className="rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white disabled:opacity-50">{busy ? 'Adding…' : 'Add'}</button>
                    <button onClick={() => { setAdding(false); setError(''); }}
                        className="rounded-md border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-sm text-gray-600 dark:text-gray-300">Cancel</button>
                </div>
            )}
            {error && <p className="text-xs text-red-600 mb-2">{error}</p>}

            {!rows.length ? (
                <p className="text-sm text-gray-400 py-6">No products yet — add what this vendor sells you.</p>
            ) : (
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200 dark:border-gray-800">
                            <th className="py-2 pr-4 font-normal">Product</th>
                            <th className="py-2 pr-4 font-normal w-32">SKU</th>
                            <th className="py-2 pr-4 font-normal w-24">Licenses</th>
                            <th className="py-2 pr-4 font-normal w-28">Annual</th>
                            <th className="py-2 pr-4 font-normal w-20">Active</th>
                            <th className="w-8" />
                        </tr>
                    </thead>
                    <tbody>{rows.map((p) => (
                        <tr key={p.id} className="group border-b border-gray-50 dark:border-gray-800">
                            <td className="py-1.5 pr-4">
                                <input defaultValue={p.name} onBlur={(e) => rename(p, e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
                                    className="w-full border-0 bg-transparent p-0 text-sm text-gray-800 dark:text-gray-200 focus:ring-0" />
                            </td>
                            <td className="py-1.5 pr-4 text-xs text-gray-400 font-mono">{p.sku || dash}</td>
                            <td className="py-1.5 pr-4 text-gray-500 dark:text-gray-400">{p.licenses || dash}</td>
                            <td className="py-1.5 pr-4 text-gray-500 dark:text-gray-400">{p.annual ? `$${p.annual.toLocaleString()}` : dash}</td>
                            <td className="py-1.5 pr-4">
                                <button onClick={() => toggleActive(p)}
                                    className={`text-xs ${p.active ? 'text-gray-500 dark:text-gray-400' : 'text-gray-300'}`}>
                                    {p.active ? 'Yes' : 'No'}
                                </button>
                            </td>
                            <td className="py-1.5 text-center">
                                <button onClick={() => remove(p)} title={p.licenses ? 'In use — deactivate instead' : 'Delete'}
                                    className="text-gray-300 dark:text-gray-600 opacity-0 group-hover:opacity-100 hover:text-red-600">
                                    <TrashIcon />
                                </button>
                            </td>
                        </tr>
                    ))}</tbody>
                </table>
            )}
            <p className="mt-3 text-xs text-gray-400">
                What this vendor sells. Licenses you actually pay for live on the Licenses tab — filter them by product.
            </p>
        </div>
    );
}
