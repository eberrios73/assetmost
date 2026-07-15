import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '@/Layouts/AppShell';

export default function Index({ devices, filters = {}, selected = null }) {
    const [search, setSearch] = useState(filters.search || '');

    const submitSearch = (e) => {
        e.preventDefault();
        router.get('/devices', { search }, { preserveState: true, replace: true });
    };

    const nav = (
        <div>
            <form onSubmit={submitSearch} className="p-3 border-b border-gray-100 sticky top-0 bg-white">
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search devices…"
                    className="w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                />
                <div className="mt-1 text-xs text-gray-400">{devices.total} devices</div>
            </form>
            <ul>
                {devices.data.map((d) => (
                    <li key={d.id}>
                        <Link
                            href={`/devices/${d.id}`}
                            preserveScroll
                            className={`block px-3 py-2 border-b border-gray-50 hover:bg-blue-50 ${selected?.id === d.id ? 'bg-blue-50' : ''}`}
                        >
                            <div className="text-sm font-medium text-gray-800">{d.asset_tag || d.computer_name || `#${d.id}`}</div>
                            <div className="text-xs text-gray-500">{d.type} · {d.brand} {d.model}</div>
                        </Link>
                    </li>
                ))}
            </ul>
            <Pagination links={devices.links} />
        </div>
    );

    const detail = selected ? <DeviceDetail device={selected} /> : (
        <div className="text-gray-400 text-sm flex items-center justify-center h-full">
            Select a device on the left
        </div>
    );

    return (
        <>
            <Head title="Devices" />
            <AppShell nav={nav} detail={detail} />
        </>
    );
}

function DeviceDetail({ device }) {
    return (
        <div className="max-w-3xl">
            <h1 className="text-xl font-semibold text-gray-800">{device.asset_tag || device.computer_name}</h1>
            <p className="text-sm text-gray-500 mb-4">{device.type} · {device.brand} {device.model}</p>
            <dl className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
                <Field label="Company" value={device.company?.name} />
                <Field label="Location" value={device.location?.name} />
                <Field label="Room" value={device.room?.name} />
                <Field label="Serial" value={device.serial_num} />
                <Field label="Service tag" value={device.service_tag} />
                <Field label="OS" value={device.op_sys} />
                <Field label="CPU" value={device.cpu} />
                <Field label="RAM" value={device.ram} />
                <Field label="Assigned to" value={device.users?.map((u) => `${u.name} ${u.last ?? ''}`).join(', ')} />
                <Field label="Active" value={device.active ? 'Yes' : 'No'} />
            </dl>
        </div>
    );
}

function Field({ label, value }) {
    return (
        <div className="border-b border-gray-100 py-1">
            <dt className="text-xs uppercase tracking-wide text-gray-400">{label}</dt>
            <dd className="text-gray-800">{value || <span className="text-gray-300">—</span>}</dd>
        </div>
    );
}

function Pagination({ links = [] }) {
    return (
        <div className="flex flex-wrap gap-1 p-3">
            {links.map((l, i) => (
                <Link
                    key={i}
                    href={l.url || '#'}
                    preserveScroll
                    dangerouslySetInnerHTML={{ __html: l.label }}
                    className={`px-2 py-1 text-xs rounded ${l.active ? 'bg-blue-600 text-white' : l.url ? 'text-gray-600 hover:bg-gray-100' : 'text-gray-300'}`}
                />
            ))}
        </div>
    );
}
