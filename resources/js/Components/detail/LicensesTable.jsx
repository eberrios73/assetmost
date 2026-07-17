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

/** Seats: "2 / 3" with 1 free. Unknown purchase count shows the used count only. */
function Seats({ used, total, over }) {
    if (total === null || total === undefined) {
        return <span className="text-gray-400">{used ?? 0} <span className="text-gray-300">/ ?</span></span>;
    }
    return (
        <span className={over ? 'text-red-600 font-medium' : 'text-gray-500 dark:text-gray-400'}>
            {used} / {total}
            {over && <span className="ml-1 text-xs">over</span>}
        </span>
    );
}

/** Shared licenses list. Rows open an editable detail drawer; "+ Add license" creates one.
 *  `showHolders` (vendor view) shows who holds the seats; otherwise shows the vendor.
 *  `defaults` pre-fills the add form (vendor view passes its own vendor_id).
 *  Holders are reached through the accounts consuming the seats, so a license can have
 *  several — or none, when seats are bought but not yet provisioned. */
export default function LicensesTable({ endpoint, showHolders = false, defaults = {} }) {
    const [reload, setReload] = useState(0);
    const { loading, data } = useJson(`${endpoint}?_=${reload}`);
    const [edit, setEdit] = useState(null);
    const [adding, setAdding] = useState(false);

    const openEdit = async (id) => {
        const l = await fetch(`/data/licenses/${id}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
        setEdit({ id, initial: l });
    };

    if (loading) return <p className="text-sm text-gray-400 py-6">Loading…</p>;

    const dash = <span className="text-gray-300">—</span>;
    const cols = showHolders
        ? ['Held by', 'License', 'Seats', 'Account #', 'Amount', 'Renews']
        : ['Name', 'Vendor', 'Account #', 'Amount', 'Renews'];

    // The add button lives OUTSIDE the empty-state check: an empty list is exactly when
    // you need it, and hiding it there was a dead end (no way to create the first one).
    const addBtn = (
        <button onClick={() => setAdding(true)}
            className="text-sm text-blue-600 dark:text-blue-400 hover:underline">+ Add license</button>
    );

    return (
        <>
            <div className="flex justify-end pb-1">{addBtn}</div>

            {!data?.length ? (
                <p className="text-sm text-gray-400 py-4">No licenses.</p>
            ) : (
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200 dark:border-gray-800">
                            {cols.map((c) => <th key={c} className="py-2 pr-4 font-normal">{c}</th>)}
                        </tr>
                    </thead>
                    <tbody>{data.map((l) => (
                        <tr key={l.id} onClick={() => openEdit(l.id)}
                            className="border-b border-gray-50 dark:border-gray-800 cursor-pointer hover:bg-blue-50/40 dark:hover:bg-gray-800/50">
                            {showHolders ? (
                                <>
                                    <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">
                                        {l.holders?.length ? l.holders.join(', ') : <span className="text-gray-300">Unassigned</span>}
                                    </td>
                                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.product || l.name || dash}</td>
                                    <td className="py-2 pr-4"><Seats used={l.seats_used} total={l.seats_total} over={l.seats_used > l.seats_total} /></td>
                                </>
                            ) : (
                                <>
                                    <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{l.name || dash}</td>
                                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.product || l.vendor || dash}</td>
                                </>
                            )}
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
