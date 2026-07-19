import { useState } from 'react';
import { PlusIcon } from "@/Components/Icons";
import Tabs from '@/Components/Tabs';
import Field, { useJson } from '@/Components/detail/Field';
import LoginsTable from '@/Components/detail/LoginsTable';
import SearchSelect from '@/Components/SearchSelect';

export default function CompanyDetail({ c }) {
    return (
        <div className="h-full flex flex-col min-h-0">
            <div className="mb-4">
                <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{c.name}</h2>
                <p className="text-sm text-gray-500">{c.domain}</p>
                <div className="grid grid-cols-3 gap-4 mt-3 max-w-xl">
                    <Stat label="People" value={c.users_count} />
                    <Stat label="Devices" value={c.devices_count} />
                    <Stat label="Locations" value={c.locations_count} />
                </div>
            </div>
            <div className="flex-1 min-h-0">
                <Tabs tabs={[
                    { key: 'overview', label: 'Overview', render: () => (
                        <dl className="grid grid-cols-2 gap-x-8 max-w-2xl">
                            <Field label="Contact" value={c.contact_name} />
                            <Field label="Email" value={c.email} />
                            <Field label="Phone" value={c.phone} />
                            <Field label="Website" value={c.website} />
                            <Field label="Address" value={[c.address, c.city, c.state, c.zip].filter(Boolean).join(', ')} />
                            <Field label="Offboard forward" value={c.offboard_email_forward_to} />
                            <Field label="Active" value={c.active ? 'Yes' : 'No'} />
                        </dl>
                    ) },
                    { key: 'clients', label: 'Clients', render: () => <ClientsTab id={c.id} /> },
                    { key: 'domainadmin', label: 'Domain admin', render: () => <DomainAdminTab id={c.id} /> },
                ]} />
            </div>
        </div>
    );
}

/** The company's related domain-admin account — the login /domainjoin resolves
 *  at script generation. Same LoginsTable as everywhere; add creates + links,
 *  or link a login that already exists in the registry. */
function DomainAdminTab({ id }) {
    const [rev, setRev] = useState(0);
    const link = async (loginId) => {
        if (!loginId) return;
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        await fetch(`/data/companies/${id}/domain-join-login`, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify({ link_id: loginId }),
        });
        setRev((v) => v + 1);
    };
    return (
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400 mb-3">
                The account <code className="text-xs bg-gray-100 dark:bg-gray-800 rounded px-1">/domainjoin</code> uses
                to join machines to the domain — a login in the registry. Rotate it there and the next generated script picks it up.
            </p>
            <LoginsTable key={rev} endpoint={`/data/companies/${id}/domain-join-login`}
                createEndpoint={`/data/companies/${id}/domain-join-login`} />
            <div className="mt-4 max-w-sm">
                <p className="mb-1 text-xs uppercase tracking-wide text-gray-400">Or link an existing login</p>
                <SearchSelect value={null} endpoint="/data/login-options" placeholder="Search the registry…" onChange={link} />
            </div>
        </div>
    );
}

function ClientsTab({ id }) {
    const { loading, data } = useJson(`/data/companies/${id}/clients`);
    if (loading) return <div className="text-sm text-gray-400 py-6">Loading…</div>;
    if (!data?.length) return (
        <div className="py-6">
            <p className="text-sm text-gray-400 mb-3">No clients under this company yet.</p>
            <button className="px-3 py-2 text-sm rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50 inline-flex items-center gap-1"><PlusIcon /> Add client</button>
        </div>
    );
    return (
        <table className="w-full text-sm">
            <tbody>{data.map((cl) => (
                <tr key={cl.id} className="border-b border-gray-50 dark:border-gray-800">
                    <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{cl.name}</td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{cl.client_code}</td>
                </tr>
            ))}</tbody>
        </table>
    );
}

function Stat({ label, value }) {
    return (
        <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-3">
            <div className="text-xl font-semibold text-gray-800">{value ?? 0}</div>
            <div className="text-xs uppercase tracking-wide text-gray-400">{label}</div>
        </div>
    );
}
