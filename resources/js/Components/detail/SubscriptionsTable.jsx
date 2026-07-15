import { useState } from 'react';
import { useJson } from '@/Components/detail/Field';
import RecordModal from '@/Components/RecordModal';

const SUB_FIELDS = [
    { key: 'subscription_name', label: 'Name', required: true },
    { key: 'vendor_id', label: 'Vendor', type: 'select-search', optionsEndpoint: '/data/vendor-options' },
    { key: 'user_id', label: 'Assigned to', type: 'select-search', optionsEndpoint: '/data/people-options', labelField: 'user_label' },
    { key: 'account_number', label: 'Account #' },
    { key: 'serial_number', label: 'Serial #' },
    { key: 'amount', label: 'Amount ($)' },
    { key: 'renewal_date', label: 'Renews', type: 'date' },
    { key: 'renewalfrequency', label: 'Frequency' },
    { key: 'is_active', label: 'Active', type: 'checkbox' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
];

/** Shared subscriptions list. Rows open an editable detail drawer.
 *  `showUser` (vendor view) shows who it's assigned to; otherwise shows the vendor. */
export default function SubscriptionsTable({ endpoint, showUser = false }) {
    const [reload, setReload] = useState(0);
    const { loading, data } = useJson(`${endpoint}?_=${reload}`);
    const [edit, setEdit] = useState(null);

    const openEdit = async (id) => {
        const s = await fetch(`/data/subscriptions/${id}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
        setEdit({ id, initial: s });
    };

    if (loading) return <p className="text-sm text-gray-400 py-6">Loading…</p>;
    if (!data?.length) return <p className="text-sm text-gray-400 py-6">No subscriptions.</p>;

    const cols = showUser ? ['User', 'Subscription', 'Account #', 'Amount', 'Renews'] : ['Name', 'Vendor', 'Account #', 'Amount', 'Renews'];

    return (
        <>
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200">
                        {cols.map((c) => <th key={c} className="py-2 pr-4 font-normal">{c}</th>)}
                    </tr>
                </thead>
                <tbody>{data.map((s) => (
                    <tr key={s.id} onClick={() => openEdit(s.id)} className="border-b border-gray-50 dark:border-gray-800 cursor-pointer hover:bg-blue-50/40">
                        <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{(showUser ? s.user : s.name) || <span className="text-gray-300">{showUser ? 'Unassigned' : '—'}</span>}</td>
                        <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{(showUser ? s.name : s.vendor) || <span className="text-gray-300">—</span>}</td>
                        <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{s.account_number || <span className="text-gray-300">—</span>}</td>
                        <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{s.amount ? `$${s.amount}` : <span className="text-gray-300">—</span>}</td>
                        <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{s.renewal_date || <span className="text-gray-300">—</span>}</td>
                    </tr>
                ))}</tbody>
            </table>

            {edit && (
                <RecordModal title="Subscription" endpoint={`/data/subscriptions/${edit.id}`} method="PATCH"
                    fields={SUB_FIELDS} initial={edit.initial}
                    onClose={() => setEdit(null)}
                    onSaved={() => { setEdit(null); setReload((r) => r + 1); }} />
            )}
        </>
    );
}
