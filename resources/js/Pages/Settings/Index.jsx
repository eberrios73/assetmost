import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '@/Layouts/AppShell';

const SECTIONS = [
    { key: 'directory', label: 'Directory (Samba / AD)' },
    { key: 'm365', label: 'Microsoft 365' },
    { key: 'email', label: 'Email & signatures' },
    { key: 'backups', label: 'Backups' },
    { key: 'roles', label: 'Roles & access' },
];

export default function Index() {
    const [section, setSection] = useState('directory');

    const nav = (
        <ul className="p-2">
            {SECTIONS.map((s) => (
                <li key={s.key}>
                    <button onClick={() => setSection(s.key)}
                        className={`w-full text-left px-3 py-2.5 rounded-md text-sm ${section === s.key ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50'}`}>
                        {s.label}
                    </button>
                </li>
            ))}
        </ul>
    );

    return (
        <>
            <Head title="Settings" />
            <AppShell active="settings" nav={nav}
                detail={<div className="max-w-3xl">{RENDER[section]()}</div>}
                footer={<span>Settings — {SECTIONS.find((s) => s.key === section)?.label}</span>} />
        </>
    );
}

const RENDER = {
    directory: () => (
        <Section title="Directory (Samba / AD)" desc="Connection and user sync.">
            <Grid rows={[['Host', '—'], ['Base DN', '—'], ['Sync on login', 'Off'], ['App is source of truth', 'Off']]} />
            <Btns labels={['Test connection', 'Sync users']} />
        </Section>
    ),
    m365: () => (
        <Section title="Microsoft 365" desc="Graph integration (device-code OAuth). Folded in here — was previously a separate screen that only half-worked before we had API access.">
            <div className="flex items-center gap-3 mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <span className="h-2.5 w-2.5 rounded-full bg-amber-400" />
                <span className="text-sm text-amber-800">Not connected — one Graph client to be wired up.</span>
            </div>
            <div className="grid grid-cols-2 gap-3">
                {['Connect (device-code)', 'Create user', 'Assign license', 'Manage groups', 'Offboard user'].map((a) => (
                    <div key={a} className="rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700">{a}</div>
                ))}
            </div>
        </Section>
    ),
    email: () => (
        <Section title="Email & signatures" desc="Transactional email provider and per-company HTML signature.">
            <Grid rows={[['Provider', '—'], ['From address', '—'], ['Signature', 'Per company']]} />
        </Section>
    ),
    backups: () => (
        <Section title="Backups" desc="Database backup schedule and downloads (admin only).">
            <Grid rows={[['Schedule', '—'], ['Last backup', '—'], ['Retention', '—']]} />
            <Btns labels={['Create backup now']} />
        </Section>
    ),
    roles: () => (
        <Section title="Roles & access" desc="Role tiers enforced across the app.">
            <Grid rows={[['SuperAdmin', 'All companies'], ['IT Admin', 'Assigned companies'], ['Company Admin', 'Own company'], ['User', 'Self only']]} />
        </Section>
    ),
};

function Section({ title, desc, children }) {
    return (
        <div>
            <h1 className="text-xl font-semibold text-gray-800 mb-1">{title}</h1>
            <p className="text-sm text-gray-500 mb-5">{desc}</p>
            {children}
        </div>
    );
}
function Grid({ rows }) {
    return (
        <dl className="grid grid-cols-2 gap-x-8 mb-5">
            {rows.map(([k, v]) => (
                <div key={k} className="border-b border-gray-100 py-1.5">
                    <dt className="text-xs uppercase tracking-wide text-gray-400">{k}</dt>
                    <dd className="text-sm text-gray-800">{v}</dd>
                </div>
            ))}
        </dl>
    );
}
function Btns({ labels }) {
    return (
        <div className="flex gap-2">
            {labels.map((l) => <button key={l} className="px-3 py-2 text-sm rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50">{l}</button>)}
        </div>
    );
}
