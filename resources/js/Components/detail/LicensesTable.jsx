import { useState } from 'react';
import { useJson } from '@/Components/detail/Field';
import RecordModal from '@/Components/RecordModal';
import DataTable from '@/Components/ui/DataTable';

const LICENSE_FIELDS = [
    { key: 'name', label: 'Name', required: true },
    { key: 'vendor_id', label: 'Vendor', type: 'select-search', optionsEndpoint: '/data/vendor-options' },
    { key: 'product_id', label: 'Product', type: 'select-search', optionsEndpoint: '/data/product-options' },
    { key: 'seats_total', label: 'Seats purchased (blank = unknown)', type: 'number' },
    // The accounts consuming the seats — this is what "held by" derives from.
    { key: 'login_ids', label: 'Accounts (logins) on this license', type: 'multi-select-search',
      optionsEndpoint: '/data/login-options', optionsKey: 'login_options', pickPlaceholder: 'Add an account…' },
    { key: 'account_number', label: 'Account #' },
    { key: 'serial_number', label: 'Serial #' },
    { key: 'amount', label: 'Amount ($)' },
    { key: 'renewal_date', label: 'Renews', type: 'date' },
    { key: 'renewalfrequency', label: 'Frequency' },
    { key: 'is_active', label: 'Active', type: 'checkbox' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
];

/** Licences — what you actually own. One DataTable like every other screen. */
export default function LicensesTable({ endpoint, showHolders = false, defaults = {} }) {
    const [reload, setReload] = useState(0);
    const { loading, data } = useJson(`${endpoint}?_=${reload}`);
    const [edit, setEdit] = useState(null);
    const [adding, setAdding] = useState(false);
    const [product, setProduct] = useState('');

    const openEdit = async (r) => {
        const l = await fetch(`/data/licenses/${r.id}`, { headers: { Accept: 'application/json' } }).then((x) => x.json());
        setEdit({ id: r.id, initial: l });
    };

    if (loading) return <p className="text-sm text-gray-400 py-6">Loading…</p>;

    const list = data || [];
    const products = [...new Set(list.map((l) => l.product).filter(Boolean))].sort();
    const rows = product ? list.filter((l) => l.product === product) : list;

    const columns = showHolders
        ? [
            { key: 'holders', label: 'Held by', width: '26%', className: 'text-gray-800 dark:text-gray-200',
              sortValue: (l) => l.holders?.join(', ') || '',
              render: (l) => l.holders?.length ? l.holders.join(', ') : <span className="text-gray-300">Unassigned</span> },
            { key: 'product', label: 'Product', width: '18%' },
            { key: 'seats', label: 'Seats', width: '10%', sortValue: (l) => l.seats_used ?? 0,
              render: (l) => l.seats_total == null
                  ? <span>{l.seats_used ?? 0} <span className="text-gray-300">/ ?</span></span>
                  : <span className={l.seats_used > l.seats_total ? 'text-red-600 font-medium' : ''}>{l.seats_used} / {l.seats_total}</span> },
            { key: 'account_number', label: 'Account #', width: '18%' },
            { key: 'amount', label: 'Amount', width: '13%', sortValue: (l) => Number(l.amount) || 0,
              render: (l) => l.amount ? `$${l.amount}` : <span className="text-gray-300">—</span> },
            { key: 'renewal_date', label: 'Renews', width: '15%' },
        ]
        : [
            { key: 'name', label: 'Name', width: '26%', className: 'text-gray-800 dark:text-gray-200' },
            { key: 'product', label: 'Product', width: '18%' },
            { key: 'vendor', label: 'Vendor', width: '16%' },
            { key: 'account_number', label: 'Account #', width: '15%' },
            { key: 'amount', label: 'Amount', width: '10%', sortValue: (l) => Number(l.amount) || 0,
              render: (l) => l.amount ? `$${l.amount}` : <span className="text-gray-300">—</span> },
            { key: 'renewal_date', label: 'Renews', width: '15%' },
        ];

    return (
        <>
            <DataTable columns={columns} rows={rows} onRowClick={openEdit}
                filters={[{ key: 'product', label: 'products', options: products, value: product, onChange: setProduct }]}
                addLabel="Add license" onAdd={() => setAdding(true)} emptyText="No licenses." />

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
