import Tabs from '@/Components/Tabs';
import Field, { useJson } from '@/Components/detail/Field';
import LoginsTable from '@/Components/detail/LoginsTable';

export default function VendorDetail({ v }) {
    return (
        <div className="h-full flex flex-col min-h-0">
            <div className="mb-4">
                <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{v.name}</h2>
                <p className="text-sm text-gray-500">{v.contact_name}</p>
            </div>
            <div className="flex-1 min-h-0">
                <Tabs tabs={[
                    { key: 'overview', label: 'Overview', render: () => (
                        <dl className="grid grid-cols-2 gap-x-8 max-w-2xl">
                            <Field label="Email" value={v.email} />
                            <Field label="Phone" value={v.phone} />
                            <Field label="Website" value={v.website} />
                            <Field label="Companies" value={v.companies?.map((c) => c.name).join(', ')} />
                            <Field label="Active" value={v.active ? 'Yes' : 'No'} />
                        </dl>
                    ) },
                    { key: 'logins', label: 'Logins', count: v.logins_count, render: () => <LoginsTable endpoint={`/data/vendors/${v.id}/logins`} showUser /> },
                    { key: 'subs', label: 'Subscriptions', count: v.subscriptions_count, render: () => <SubsTab id={v.id} /> },
                ]} />
            </div>
        </div>
    );
}

function Empty({ children }) { return <div className="text-sm text-gray-400 py-6">{children}</div>; }

function SubsTab({ id }) {
    const { loading, data } = useJson(`/data/vendors/${id}/subscriptions`);
    if (loading) return <Empty>Loading…</Empty>;
    if (!data?.length) return <Empty>No subscriptions.</Empty>;
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200">
                    <th className="py-2 pr-4 font-normal">User</th>
                    <th className="py-2 pr-4 font-normal">Subscription</th>
                    <th className="py-2 pr-4 font-normal">Account #</th>
                    <th className="py-2 pr-4 font-normal">Amount</th>
                    <th className="py-2 pr-4 font-normal">Renews</th>
                </tr>
            </thead>
            <tbody>{data.map((s) => (
                <tr key={s.id} className="border-b border-gray-50 dark:border-gray-800">
                    <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{s.user || <span className="text-gray-300">Unassigned</span>}</td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{s.name}</td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{s.account_number || <span className="text-gray-300">—</span>}</td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{s.amount ? `$${s.amount}` : <span className="text-gray-300">—</span>}</td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{s.renewal_date}</td>
                </tr>
            ))}</tbody>
        </table>
    );
}
