import { useState } from 'react';
import Tabs from '@/Components/Tabs';
import Field, { useJson } from '@/Components/detail/Field';
import LoginsTable from '@/Components/detail/LoginsTable';
import LicensesTable from '@/Components/detail/LicensesTable';
import SearchSelect from '@/Components/SearchSelect';
import { DeviceIcon, PlusIcon } from '@/Components/Icons';

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const send = (url, method, body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
});

export default function PersonDetail({ u }) {
    return (
        <div className="h-full flex flex-col min-h-0">
            <div className="mb-4">
                <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{u.name} {u.last}</h2>
                <p className="text-sm text-gray-500">{u.title}{u.department ? ` · ${u.department}` : ''}</p>
                <dl className="grid grid-cols-2 gap-x-8 mt-3 max-w-2xl">
                    <Field label="Email" value={u.email} />
                    <Field label="Ext" value={u.ext} />
                    <Field label="Cell" value={u.cell} />
                    <Field label="Location" value={u.location?.name} />
                    <Field label="Role" value={u.role} />
                    <Field label="Active" value={u.active ? 'Yes' : 'No'} />
                </dl>
            </div>
            <div className="flex-1 min-h-0">
                <Tabs
                    tabs={[
                        { key: 'logins', label: 'Logins', count: u.logins_count, render: () => <LoginsTable endpoint={`/data/people/${u.id}/logins`} createEndpoint={`/data/people/${u.id}/logins`} /> },
                        // One tab, not two — "Licenses" and "Subscriptions" were the same
                        // endpoint rendered twice under different names.
                        { key: 'licenses', label: 'Licenses', count: u.licenses_count, render: () => <LicensesTable endpoint={`/data/people/${u.id}/licenses`} /> },
                        { key: 'devices', label: 'Devices', count: u.devices_count, render: () => <DevicesTab id={u.id} /> },
                    ]}
                />
            </div>
        </div>
    );
}

function Empty({ children }) {
    return <div className="text-sm text-gray-400 py-6">{children}</div>;
}

function DevicesTab({ id }) {
    const [reload, setReload] = useState(0);
    const { loading, data } = useJson(`/data/people/${id}/devices?_=${reload}`);
    const [assigning, setAssigning] = useState(false);
    const bump = () => setReload((r) => r + 1);

    const attach = async (deviceId) => {
        if (!deviceId) return;
        await send(`/data/people/${id}/devices`, 'POST', { device_id: deviceId });
        setAssigning(false); bump();
    };
    const detach = async (deviceId) => { await send(`/data/people/${id}/devices/${deviceId}`, 'DELETE'); bump(); };

    return (
        <div>
            <div className="flex justify-end mb-2">
                <button onClick={() => setAssigning((a) => !a)} className="inline-flex items-center gap-1 text-sm rounded-md border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800"><PlusIcon /> Assign device</button>
            </div>
            {assigning && (
                <div className="mb-3 max-w-md">
                    <SearchSelect value={null} endpoint="/data/device-options" onChange={attach} placeholder="Search a device to assign…" />
                </div>
            )}
            {loading ? <Empty>Loading…</Empty> : !data?.length ? <Empty>No devices assigned.</Empty> : (
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200 dark:border-gray-800">
                            <th className="w-8" />
                            <th className="py-2 pr-4 font-normal whitespace-nowrap">Asset Tag</th>
                            <th className="py-2 pr-4 font-normal whitespace-nowrap">Computer</th>
                            <th className="py-2 pr-4 font-normal whitespace-nowrap">Type</th>
                            <th className="py-2 pr-4 font-normal">Model</th>
                            <th className="w-8" />
                        </tr>
                    </thead>
                    <tbody>{data.map((d) => (
                        <tr key={d.id} className="group border-b border-gray-50 dark:border-gray-800 hover:bg-blue-50/30 dark:hover:bg-blue-500/10">
                            <td className="py-2 pl-1 text-gray-400 dark:text-gray-500"><DeviceIcon /></td>
                            <td className="py-2 pr-4 font-medium text-gray-800 dark:text-gray-100 whitespace-nowrap">{d.asset_tag || <span className="text-gray-300">—</span>}</td>
                            <td className="py-2 pr-4 text-gray-700 dark:text-gray-300 whitespace-nowrap">{d.computer_name || <span className="text-gray-300">—</span>}</td>
                            <td className="py-2 pr-4 text-gray-700 dark:text-gray-300 whitespace-nowrap">{d.type || <span className="text-gray-300">—</span>}</td>
                            <td className="py-2 pr-4 text-gray-600 dark:text-gray-400">{[d.brand, d.model].filter(Boolean).join(' ') || <span className="text-gray-300">—</span>}</td>
                            <td className="py-2 pr-2 text-right">
                                <button onClick={() => detach(d.id)} title="Unassign" className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-600">×</button>
                            </td>
                        </tr>
                    ))}</tbody>
                </table>
            )}
        </div>
    );
}

