import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import RecordModal from '@/Components/RecordModal';
import DataTable from '@/Components/ui/DataTable';
import { ENTITIES } from '@/entities';

const SECTIONS = [
    { key: 'companies', label: 'Companies' },
    { key: 'identity', label: 'Identity & integrations' },
    { key: 'installers', label: 'Installers' },
    { key: 'email', label: 'Email & signatures' },
    { key: 'backups', label: 'Backups' },
    { key: 'roles', label: 'Roles & access' },
];

export default function Index() {
    const [section, setSection] = useState('companies');

    const nav = (
        <ul className="p-2">
            {SECTIONS.map((s) => (
                <li key={s.key}>
                    <button onClick={() => setSection(s.key)}
                        className={`w-full text-left px-3 py-2.5 rounded-md text-sm ${section === s.key ? 'bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300 font-medium' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'}`}>
                        {s.label}
                    </button>
                </li>
            ))}
        </ul>
    );

    const RENDER = {
        companies: <Companies />,
        identity: <Identity />,
        installers: <Installers />,
        email: <Email />,
        backups: <Backups />,
        roles: <RolesAccess />,
    };

    return (
        <>
            <Head title="Settings" />
            <AppShell active="settings" nav={nav}
                detail={<div className="max-w-4xl">{RENDER[section]}</div>}
                footer={<span>Settings — {SECTIONS.find((s) => s.key === section)?.label}</span>} />
        </>
    );
}

/* ---------------- Companies ---------------- */

function Companies() {
    const { companies = [] } = usePage().props;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState(null);

    const columns = [
        { key: 'name', label: 'Name', width: '28%', className: 'text-gray-800 dark:text-gray-100' },
        { key: 'tag_prefix', label: 'Tag prefix', width: '15%',
          render: (c) => c.tag_prefix ? <span className="font-mono text-xs">{c.tag_prefix}</span> : <span className="text-gray-300">—</span> },
        { key: 'domain', label: 'Domain', width: '22%' },
        { key: 'location', label: 'Location', width: '20%', sortValue: (c) => [c.city, c.state].filter(Boolean).join(', '),
          render: (c) => [c.city, c.state].filter(Boolean).join(', ') || <span className="text-gray-300">—</span> },
        { key: 'active', label: 'Status', width: '15%', sortValue: (c) => (c.active ? 1 : 0),
          render: (c) => <span className={c.active ? 'text-green-600 dark:text-green-400' : 'text-gray-400'}>{c.active ? 'Active' : 'Inactive'}</span> },
    ];

    return (
        <Section title="Companies" desc="Every company in this install. Assets, people and licences all hang off one of these. Click a row to edit.">
            <DataTable columns={columns} rows={companies} onRowClick={setEditing}
                addLabel="Add company" onAdd={() => setAdding(true)} emptyText="No companies yet." />

            {adding && (
                <RecordModal title="Add Company" endpoint="/data/companies" method="POST"
                    fields={ENTITIES.companies.add.fields}
                    onClose={() => setAdding(false)}
                    onSaved={() => { setAdding(false); router.reload({ only: ['companies'] }); }} />
            )}

            {editing && (
                <RecordModal title={editing.name} endpoint={`/data/companies/${editing.id}`} method="PATCH"
                    fields={ENTITIES.companies.edit.fields} initial={editing}
                    onClose={() => setEditing(null)}
                    onSaved={() => { setEditing(null); router.reload({ only: ['companies'] }); }} />
            )}
        </Section>
    );
}

/* ---------------- Identity providers ---------------- */

/**
 * Per company, because identity is a per-company fact: two companies in one install don't
 * share a Google tenant. The old Directory (Samba/AD) section is gone — it needs software
 * reachable on the company's own LAN, which a self-hosted box elsewhere isn't.
 */
function Identity() {
    const { companies = [], providers = [], providerTypes = {} } = usePage().props;
    const [companyId, setCompanyId] = useState(companies[0]?.id ?? null);

    const forCompany = (p) => providers.find((x) => x.company_id === companyId && x.provider === p);

    if (!companies.length) {
        return (
            <Section title="Identity providers" desc="Where a company's people come from.">
                <Empty>Add a company first — identity is configured per company.</Empty>
            </Section>
        );
    }

    return (
        <Section title="Identity providers" desc="Where a company's people come from. Sync only: the provider says who exists, this app decides what they may do.">
            <label className="mb-5 block">
                <span className="mb-1 block text-xs uppercase tracking-wide text-gray-400">Company</span>
                <select value={companyId ?? ''} onChange={(e) => setCompanyId(Number(e.target.value))}
                    className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm">
                    {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </label>

            <div className="space-y-3">
                {Object.entries(providerTypes).map(([key, label]) => (
                    <ProviderCard key={key} providerKey={key} label={label}
                        companyId={companyId} existing={forCompany(key)} />
                ))}
            </div>

            <p className="mt-5 text-xs text-gray-400">
                Identity sync isn't wired up yet; provisioning plugins (Zoom and any pasted below) fire
                during onboarding when enabled here. A person arriving from a sync is a directory record:
                whether they can sign in stays a local decision on Roles &amp; access.
            </p>

            <PluginDefs />
        </Section>
    );
}

/**
 * Declarative provisioning plugins: paste a JSON field map, it becomes an
 * integration card above. One request per plugin — that's the security model;
 * community plugins are readable at a glance and can't reach anything else.
 */
function PluginDefs() {
    const { pluginDefs = [] } = usePage().props;
    const [text, setText] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const add = async () => {
        setError(null);
        let parsed;
        try { parsed = JSON.parse(text); } catch { setError('Not valid JSON.'); return; }
        setBusy(true);
        const r = await post('/settings/provisioner-defs', { definition: parsed });
        setBusy(false);
        if (!r) { setError('Rejected — needs plugin_key, name, and request.'); return; }
        setText('');
        router.reload();
    };

    return (
        <div className="mt-6 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
            <p className="text-sm font-medium text-gray-800 dark:text-gray-100 mb-1">Provisioning plugins (JSON)</p>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                A plugin is a field map: auth recipe + one request. Paste one (yours or the community's) and its
                integration card appears above. It can only ever make the single request it declares.
            </p>
            {pluginDefs.length > 0 && (
                <ul className="mb-3 text-sm text-gray-600 dark:text-gray-300 space-y-1">
                    {pluginDefs.map((d) => (
                        <li key={d.id} className="flex items-center gap-2">
                            <span className={`h-2 w-2 rounded-full ${d.enabled ? 'bg-green-500' : 'bg-gray-300'}`} />
                            {d.name} <code className="text-xs text-gray-400">{d.plugin_key}</code>
                        </li>
                    ))}
                </ul>
            )}
            <textarea rows={6} value={text} onChange={(e) => setText(e.target.value)}
                placeholder={'{ "plugin_key": "slack", "name": "Slack", "matches": "slack",\n  "auth": { "type": "bearer" },\n  "request": { "method": "POST", "url": "https://slack.com/api/admin.users.invite", "body": { "email": "{email}" } },\n  "success": [200] }'}
                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs font-mono focus:border-blue-500 focus:ring-blue-500" />
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
            <button onClick={add} disabled={busy || !text.trim()}
                className="mt-2 px-3 py-1.5 text-sm rounded-md bg-blue-600 text-white disabled:opacity-50">
                {busy ? 'Saving…' : 'Add plugin'}
            </button>
        </div>
    );
}

function ProviderCard({ providerKey, label, companyId, existing }) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(() => ({
        domain: existing?.domain ?? '', tenant_id: existing?.tenant_id ?? '',
        client_id: existing?.client_id ?? '', client_secret: '',
        enabled: existing?.enabled ?? false, sync_on_login: existing?.sync_on_login ?? false,
    }));
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);
        await post('/settings/identity-providers', { company_id: companyId, provider: providerKey, ...form });
        setSaving(false); setOpen(false);
        router.reload({ only: ['providers'] });
    };

    return (
        <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900">
            <div className="flex items-center justify-between p-4">
                <div className="flex items-center gap-3">
                    <span className={`h-2.5 w-2.5 rounded-full ${existing?.enabled ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600'}`} />
                    <div>
                        <div className="text-sm font-medium text-gray-800 dark:text-gray-100">{label}</div>
                        <div className="text-xs text-gray-400">
                            {existing?.enabled ? `Connected${existing.domain ? ` — ${existing.domain}` : ''}` : 'Not connected'}
                        </div>
                    </div>
                </div>
                <button onClick={() => setOpen((o) => !o)}
                    className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                    {open ? 'Cancel' : existing ? 'Edit' : 'Configure'}
                </button>
            </div>

            {open && (
                <div className="border-t border-gray-100 dark:border-gray-800 p-4 space-y-3">
                    <Field label={providerKey === 'okta' ? 'Org URL' : 'Domain'} value={form.domain}
                        onChange={(v) => setForm((f) => ({ ...f, domain: v }))}
                        placeholder={providerKey === 'okta' ? 'acme.okta.com' : 'acme.com'} />
                    {(providerKey === 'microsoft' || providerKey === 'zoom') && (
                        <Field label={providerKey === 'zoom' ? 'Zoom Account ID' : 'Directory (tenant) ID'} value={form.tenant_id}
                            onChange={(v) => setForm((f) => ({ ...f, tenant_id: v }))} />
                    )}
                    <Field label="Client ID" value={form.client_id} onChange={(v) => setForm((f) => ({ ...f, client_id: v }))} />
                    <Field label="Client secret" type="password" value={form.client_secret}
                        onChange={(v) => setForm((f) => ({ ...f, client_secret: v }))}
                        placeholder={existing?.has_secret ? 'Stored — leave blank to keep' : ''} />
                    <div className="flex gap-6 pt-1">
                        <Check label="Enabled" checked={form.enabled} onChange={(v) => setForm((f) => ({ ...f, enabled: v }))} />
                        <Check label="Sync on login" checked={form.sync_on_login} onChange={(v) => setForm((f) => ({ ...f, sync_on_login: v }))} />
                    </div>
                    <button onClick={save} disabled={saving}
                        className="px-3 py-2 text-sm rounded-md bg-blue-600 text-white disabled:opacity-50">
                        {saving ? 'Saving…' : 'Save'}
                    </button>
                </div>
            )}
        </div>
    );
}

/* ---------------- Roles & access ---------------- */

/**
 * The permission matrix, editable.
 *
 * Passwords are the row that matters: they're an encrypted column, and reading one needs
 * the escrow key, so ticking the box is necessary but not sufficient. SuperAdmin is locked
 * — a screen that grants permissions must not be able to revoke the permission to reach it.
 */
function RolesAccess() {
    const { access } = usePage().props;
    const [matrix, setMatrix] = useState(access.matrix);
    const [saving, setSaving] = useState(false);

    const groups = useMemo(() => {
        const out = [];
        access.permissions.forEach((p) => {
            const g = out.find((x) => x.name === p.group);
            (g ? g.items : (out.push({ name: p.group, items: [] }), out[out.length - 1].items)).push(p);
        });
        return out;
    }, [access.permissions]);

    const dirty = JSON.stringify(matrix) !== JSON.stringify(access.matrix);

    const toggle = (role, key) =>
        setMatrix((m) => ({ ...m, [role]: { ...m[role], [key]: !m[role][key] } }));

    const save = async () => {
        setSaving(true);
        await post('/settings/roles', { matrix }, 'PATCH');
        setSaving(false);
        router.reload({ only: ['access'] });
    };

    const reset = async () => {
        setSaving(true);
        const body = await post('/settings/roles/reset', {});
        if (body?.matrix) setMatrix(body.matrix);
        setSaving(false);
        router.reload({ only: ['access'] });
    };

    return (
        <Section title="Roles & access" desc="What each role may do. Passwords are the line that matters — everything else is operational data.">
            <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th className="px-3 py-2 text-left text-xs uppercase tracking-wide text-gray-400">Permission</th>
                            {access.roles.map((r) => (
                                <th key={r} className="px-3 py-2 text-center text-xs font-medium text-gray-600 dark:text-gray-300 w-28">
                                    {r}
                                    {r === access.locked && <div className="text-[10px] font-normal text-gray-400">locked</div>}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {groups.map((g) => (
                            <RoleGroup key={g.name} group={g} access={access} matrix={matrix} toggle={toggle} />
                        ))}
                    </tbody>
                </table>
            </div>

            <p className="mt-3 text-xs text-gray-400">
                <span className="text-amber-600 dark:text-amber-400">Reveal passwords</span> also requires the
                key escrow service. Ticking it here grants the right; without the key there's nothing to decrypt.
            </p>

            {access.editable ? (
                <div className="mt-5 flex items-center gap-2">
                    <button onClick={save} disabled={!dirty || saving}
                        className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white disabled:opacity-40">
                        {saving ? 'Saving…' : 'Save changes'}
                    </button>
                    <button onClick={reset} disabled={saving}
                        className="px-3 py-2 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                        Reset to defaults
                    </button>
                    {dirty && <span className="text-xs text-amber-600 dark:text-amber-400">Unsaved changes</span>}
                </div>
            ) : (
                <p className="mt-5 text-xs text-gray-400">Your role can't change these.</p>
            )}
        </Section>
    );
}

function RoleGroup({ group, access, matrix, toggle }) {
    return (
        <>
            <tr className="bg-gray-50/60 dark:bg-gray-900/60">
                <td colSpan={access.roles.length + 1}
                    className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{group.name}</td>
            </tr>
            {group.items.map((p) => (
                <tr key={p.key} className="border-t border-gray-100 dark:border-gray-800">
                    <td className="px-3 py-2 text-gray-700 dark:text-gray-200">
                        {p.label}
                        {p.keyed && <span className="ml-2 rounded bg-amber-100 dark:bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400">escrow key</span>}
                    </td>
                    {access.roles.map((r) => {
                        const locked = r === access.locked || !access.editable;
                        return (
                            <td key={r} className="px-3 py-2 text-center">
                                <input type="checkbox" checked={!!matrix[r]?.[p.key]} disabled={locked}
                                    onChange={() => toggle(r, p.key)}
                                    className="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-blue-600 focus:ring-blue-500 disabled:opacity-40" />
                            </td>
                        );
                    })}
                </tr>
            ))}
        </>
    );
}

/* ---------------- Still mockups ---------------- */

/**
 * The installers repository — where /install reads its list from. The directory
 * IS the catalog: whatever's in the share is what /install offers.
 */
function Installers() {
    const [companies, setCompanies] = useState([]);
    const [scanning, setScanning] = useState(false);
    const [status, setStatus] = useState({});
    const [saved, setSaved] = useState('');
    // Fetch the current config directly so the saved path always shows on load.
    const load = () => fetch('/settings/installers-config', { headers: { Accept: 'application/json' } })
        .then((r) => r.json()).then((d) => { setCompanies(d.companies || []); setStatus(d); });
    useEffect(() => { load(); }, []);

    const savePath = async (id, path) => {
        setCompanies((cs) => cs.map((c) => (c.id === id ? { ...c, installers_path: path, _saved: true } : c)));
        await post('/settings/installers-path', { company_id: id, path });
        setSaved('Saved');
        setTimeout(() => { setSaved(''); setCompanies((cs) => cs.map((c) => (c.id === id ? { ...c, _saved: false } : c))); }, 1500);
    };
    const scan = async () => {
        setScanning(true);
        const r = await post('/settings/installers-scan', {});
        setScanning(false);
        if (r?.ok) setStatus((s) => ({ ...s, count: r.count, last_scan: r.last_scan }));
        else alert(r?.error || 'Scan failed.');
    };

    return (
        <Section title="Installers" desc="Your installers on the Synology Web Station, listed over HTTP. /install in a runbook reads this list; the bench curls the file when installing.">
            <div className="mb-5 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <div className="text-2xl font-semibold text-gray-800 dark:text-gray-100">{status.count ?? 0}</div>
                        <div className="text-xs uppercase tracking-wide text-gray-400">installers indexed{status.last_scan ? ` · last scan ${new Date(status.last_scan).toLocaleString()}` : ''}</div>
                    </div>
                    <button onClick={scan} disabled={scanning}
                        className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">
                        {scanning ? 'Scanning…' : 'Scan now'}
                    </button>
                </div>
            </div>

            <p className="text-xs uppercase tracking-wide text-gray-400 mb-2">Web Station base URL per company (e.g. http://files.example.com:8080)</p>
            {companies.map((c) => (
                <label key={c.id} className="mb-2 block">
                    <span className="mb-1 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        {c.name}
                        {c._saved && <span className="text-xs text-green-600">Saved ✓</span>}
                    </span>
                    <input value={c.installers_path || ''} placeholder="http://files.example.com:8080"
                        onChange={(e) => setCompanies((cs) => cs.map((x) => (x.id === c.id ? { ...x, installers_path: e.target.value } : x)))}
                        onBlur={(e) => savePath(c.id, e.target.value)}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                </label>
            ))}

            <div className="mt-4 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">
                <p className="font-medium mb-1">One-time setup on the Synology</p>
                <ol className="list-decimal ml-4 space-y-0.5">
                    <li>Drop <code>installers.php</code> (from the app repo, public/synology-installers.php) into the Installers web folder.</li>
                    <li>Assign a PHP 8.x backend to that Web Station service.</li>
                    <li>Test <code>{`{base}`}/installers.php</code> in a browser — it returns a JSON file list.</li>
                    <li>Set the base URL above and hit "Scan now". The bench downloads files unauthenticated from the same base; no login anywhere.</li>
                </ol>
            </div>
        </Section>
    );
}

function Email() {
    return (
        <Section title="Email & signatures" desc="Transactional email provider and per-company HTML signature.">
            <Grid rows={[['Provider', '—'], ['From address', '—'], ['Signature', 'Per company']]} />
            <Empty>Not wired up yet.</Empty>
        </Section>
    );
}

function Backups() {
    return (
        <Section title="Backups" desc="Database backup schedule and downloads.">
            <Grid rows={[['Schedule', '—'], ['Last backup', '—'], ['Retention', '—']]} />
            <Empty>Not wired up yet.</Empty>
        </Section>
    );
}

/* ---------------- bits ---------------- */

async function post(url, body, method = 'POST') {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const res = await fetch(url, {
        method, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
        body: JSON.stringify(body),
    });
    return res.ok ? res.json().catch(() => ({})) : null;
}

function Section({ title, desc, children }) {
    return (
        <div>
            <h1 className="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-1">{title}</h1>
            <p className="text-sm text-gray-500 dark:text-gray-400 mb-5">{desc}</p>
            {children}
        </div>
    );
}

function Grid({ rows }) {
    return (
        <dl className="grid grid-cols-2 gap-x-8 mb-5">
            {rows.map(([k, v]) => (
                <div key={k} className="border-b border-gray-100 dark:border-gray-800 py-1.5">
                    <dt className="text-xs uppercase tracking-wide text-gray-400">{k}</dt>
                    <dd className="text-sm text-gray-800 dark:text-gray-100">{v}</dd>
                </div>
            ))}
        </dl>
    );
}

function Empty({ children }) {
    return <div className="rounded-lg border border-dashed border-gray-200 dark:border-gray-800 p-4 text-sm text-gray-400">{children}</div>;
}

function Field({ label, value, onChange, type = 'text', placeholder = '' }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs uppercase tracking-wide text-gray-400">{label}</span>
            <input type={type} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)}
                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
        </label>
    );
}

function Check({ label, checked, onChange }) {
    return (
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)}
                className="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-blue-600 focus:ring-blue-500" />
            {label}
        </label>
    );
}
