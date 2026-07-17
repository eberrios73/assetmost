import { useState } from 'react';
import { useJson } from '@/Components/detail/Field';
import RecordModal from '@/Components/RecordModal';

const LICENSE_FIELDS = [
    { key: 'name', label: 'Name', required: true },
    { key: 'vendor_id', label: 'Vendor', type: 'select-search', optionsEndpoint: '/data/vendor-options' },
    { key: 'product_id', label: 'Product', type: 'select-search', optionsEndpoint: '/data/product-options' },
    // seats_total is deliberately optional — real counts get filled in over time, and
    // "unknown" must not read as "none available".
    { key: 'seats_total', label: 'Seats purchased (blank = unknown)', type: 'number' },
    { key: 'account_number', label: 'Account #' },
    { key: 'serial_number', label: 'Serial #' },
    { key: 'amount', label: 'Amount ($)' },
    { key: 'renewal_date', label: 'Renews', type: 'date' },
    { key: 'renewalfrequency', label: 'Frequency' },
    { key: 'is_active', label: 'Active', type: 'checkbox' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
];

/**
 * Licences — what you actually own. Rows open an editable drawer; "+ Add license" creates.
 *
 * Product is a column here rather than a parent: a vendor's catalog can run to hundreds of
 * SKUs, so nesting these under products would bury them. Sort/filter by product instead.
 * The filter renders even with one (or zero) products — a control that hides itself reads
 * as a control that's missing.
 * `showHolders` (vendor view) shows who holds it; `defaults` pre-fills the add form.
 */
export default function LicensesTable({ endpoint, showHolders = false, defaults = {} }) {
    const [reload, setReload] = useState(0);
    const { loading, data } = useJson(`${endpoint}?_=${reload}`);
    const [edit, setEdit] = useState(null);
    const [adding, setAdding] = useState(false);
    const [product, setProduct] = useState('');       // filter
    const [sort, setSort] = useState({ key: null, dir: 'asc' });

    const openEdit = async (id) => {
        const l = await fetch(`/data/licenses/${id}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
        setEdit({ id, initial: l });
    };

    if (loading) return <p className="text-sm text-gray-400 py-6">Loading…</p>;

    const list = data || [];
    const products = [...new Set(list.map((l) => l.product).filter(Boolean))].sort();
    let rows = product ? list.filter((l) => l.product === product) : list;
    if (sort.key) {
        rows = [...rows].sort((a, b) => {
            const A = a[sort.key] ?? '', B = b[sort.key] ?? '';
            const c = typeof A === 'number' && typeof B === 'number' ? A - B : String(A).localeCompare(String(B));
            return sort.dir === 'asc' ? c : -c;
        });
    }
    const toggleSort = (k) => setSort((s) => ({ key: k, dir: s.key === k && s.dir === 'asc' ? 'desc' : 'asc' }));

    const dash = <span className="text-gray-300">—</span>;
    const arrow = (k) => (sort.key === k ? (sort.dir === 'asc' ? ' ↑' : ' ↓') : '');

    return (
        <>
            <div className="flex items-center gap-2 mb-3">
                <select value={product} onChange={(e) => setProduct(e.target.value)}
                    className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 text-xs py-1 text-gray-600 dark:text-gray-300 focus:border-blue-500 focus:ring-blue-500">
                    <option value="">All products</option>
                    {products.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <span className="text-xs text-gray-400">{rows.length} of {list.length}</span>
                <button onClick={() => setAdding(true)}
                    className="ml-auto text-sm text-blue-600 dark:text-blue-400 hover:underline">+ Add license</button>
            </div>

            {!rows.length ? (
                <p className="text-sm text-gray-400 py-4">No licenses.</p>
            ) : (
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200 dark:border-gray-800">
                            <Th onClick={() => toggleSort(showHolders ? 'holders' : 'name')}>{showHolders ? 'Held by' : 'Name'}{arrow(showHolders ? 'holders' : 'name')}</Th>
                            <Th onClick={() => toggleSort('product')}>Product{arrow('product')}</Th>
                            {!showHolders && <Th onClick={() => toggleSort('vendor')}>Vendor{arrow('vendor')}</Th>}
                            <Th onClick={() => toggleSort('account_number')}>Account #{arrow('account_number')}</Th>
                            <Th onClick={() => toggleSort('amount')}>Amount{arrow('amount')}</Th>
                            <Th onClick={() => toggleSort('renewal_date')}>Renews{arrow('renewal_date')}</Th>
                        </tr>
                    </thead>
                    <tbody>{rows.map((l) => (
                        <tr key={l.id} onClick={() => openEdit(l.id)}
                            className="border-b border-gray-50 dark:border-gray-800 cursor-pointer hover:bg-blue-50/40 dark:hover:bg-gray-800/50">
                            <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">
                                {showHolders
                                    ? (l.holders?.length ? l.holders.join(', ') : <span className="text-gray-300">Unassigned</span>)
                                    : (l.name || dash)}
                            </td>
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.product || dash}</td>
                            {!showHolders && <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.vendor || dash}</td>}
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.account_number || dash}</td>
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.amount ? `$${l.amount}` : dash}</td>
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.renewal_date || dash}</td>
                        </tr>
                    ))}</tbody>
                </table>
            )}

            {adding && (
                <RecordModal title="Add License" endpoint="/data/licenses" method="POST"
                    fields={LICENSE_FIELDS} initial={{ is_active: true, ...defaults }}
                    onClose={() => setAdding(false)}
                    onSaved={() => { setAdding(false); setReload((r) => r + 1); }} />
            )}

            {edit && (
                <RecordModal title="License" endpoint={`/data/licenses/${edit.id}`} method="PATCH"
                    fields={LICENSE_FIELDS} initial={edit.initial}
                    onClose={() => setEdit(null)}
                    onSaved={() => { setEdit(null); setReload((r) => r + 1); }} />
            )}
        </>
    );
}

function Th({ children, onClick }) {
    return (
        <th onClick={onClick} className="py-2 pr-4 font-normal cursor-pointer select-none hover:text-gray-600 dark:hover:text-gray-200">
            {children}
        </th>
    );
}
