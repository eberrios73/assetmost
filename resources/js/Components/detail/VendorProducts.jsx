import { useEffect, useState } from 'react';
import DataTable from '@/Components/ui/DataTable';
import { TrashIcon } from '@/Components/Icons';

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const api = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then(async (r) => ({ ok: r.ok, status: r.status, body: r.status === 204 ? {} : await r.json().catch(() => ({})) }));

/**
 * A vendor's product catalog — Adobe ▸ Creative Cloud, Firefly, Substance.
 *
 * Catalog only: licences are NOT nested here (they live on the Licenses tab, filtered by
 * product). Same DataTable as everywhere; the name cell stays inline-editable.
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

    const columns = [
        { key: 'name', label: 'Product', width: '34%', className: 'text-gray-800 dark:text-gray-200',
          render: (p) => (
              <input defaultValue={p.name} onBlur={(e) => rename(p, e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); }}
                  onClick={(e) => e.stopPropagation()}
                  className="w-full border-0 bg-transparent p-0 text-sm text-gray-800 dark:text-gray-200 focus:ring-0" />
          ) },
        { key: 'sku', label: 'SKU', width: '16%',
          render: (p) => p.sku ? <span className="text-xs font-mono text-gray-400">{p.sku}</span> : <span className="text-gray-300">—</span> },
        { key: 'licenses', label: 'Licenses', width: '13%', sortValue: (p) => p.licenses || 0 },
        { key: 'annual', label: 'Annual', width: '15%', sortValue: (p) => p.annual || 0,
          render: (p) => p.annual ? `$${p.annual.toLocaleString()}` : <span className="text-gray-300">—</span> },
        { key: 'active', label: 'Active', width: '12%', sortValue: (p) => (p.active ? 1 : 0),
          render: (p) => (
              <button onClick={(e) => { e.stopPropagation(); toggleActive(p); }}
                  className={`text-xs ${p.active ? 'text-gray-500 dark:text-gray-400' : 'text-gray-300'}`}>
                  {p.active ? 'Yes' : 'No'}
              </button>
          ) },
        { key: '_actions', label: '', width: '10%', sortValue: () => '',
          render: (p) => (
              <button onClick={(e) => { e.stopPropagation(); remove(p); }}
                  title={p.licenses ? 'In use — deactivate instead' : 'Delete'}
                  className="text-gray-300 dark:text-gray-600 hover:text-red-600"><TrashIcon /></button>
          ) },
    ];

    return (
        <div>
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

            <DataTable columns={columns} rows={rows}
                addLabel="Add product" onAdd={() => { setAdding(true); setError(''); }}
                emptyText="No products yet — add what this vendor sells you." />

            <p className="mt-3 text-xs text-gray-400">
                What this vendor sells. Licenses you actually pay for live on the Licenses tab — filter them by product.
            </p>
        </div>
    );
}
