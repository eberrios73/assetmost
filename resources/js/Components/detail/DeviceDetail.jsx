import Tabs from '@/Components/Tabs';
import Field, { useJson } from '@/Components/detail/Field';

export default function DeviceDetail({ d }) {
    return (
        <div className="h-full flex flex-col min-h-0">
            <div className="mb-4">
                <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{d.asset_tag || d.computer_name}</h2>
                <p className="text-sm text-gray-500">{d.type} · {d.brand} {d.model}</p>
            </div>
            <div className="flex-1 min-h-0">
                <Tabs tabs={[
                    { key: 'overview', label: 'Overview', render: () => (
                        <dl className="grid grid-cols-2 gap-x-8 max-w-2xl">
                            <Field label="Location" value={d.location?.name} />
                            <Field label="Room" value={d.room?.name} />
                            <Field label="IP" value={d.ip_1} />
                            <Field label="IP 2" value={d.ip_2} />
                            <Field label="Serial" value={d.serial_num} />
                            <Field label="Service tag" value={d.service_tag} />
                            <Field label="OS" value={d.op_sys} />
                            <Field label="CPU" value={d.cpu} />
                            <Field label="RAM" value={d.ram} />
                            <Field label="Active" value={d.active ? 'Yes' : 'No'} />
                        </dl>
                    ) },
                    { key: 'users', label: 'Assigned Users', count: d.users_count, render: () => <UsersTab id={d.id} /> },
                ]} />
            </div>
        </div>
    );
}

function UsersTab({ id }) {
    const { loading, data } = useJson(`/data/devices/${id}/users`);
    if (loading) return <div className="text-sm text-gray-400 py-6">Loading…</div>;
    if (!data?.length) return <div className="text-sm text-gray-400 py-6">Not assigned to anyone.</div>;
    return (
        <table className="w-full text-sm">
            <tbody>{data.map((u) => (
                <tr key={u.id} className="border-b border-gray-50 dark:border-gray-800">
                    <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{u.name} {u.last}</td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{u.title}</td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{u.email}</td>
                </tr>
            ))}</tbody>
        </table>
    );
}
