import { useState } from 'react';
import { useJson } from '@/Components/detail/Field';
import RecordModal from '@/Components/RecordModal';
import { CopyIcon, KeyIcon, ChatIcon, TrashIcon, PlusIcon, EyeIcon, EyeOffIcon } from '@/Components/Icons';

const LOGIN_FIELDS = [
    { key: 'vendor_id', label: 'Vendor', type: 'select-search', optionsEndpoint: '/data/vendor-options' },
    { key: 'login_name', label: 'Name', required: true },
    { key: 'login_id', label: 'Login ID' },
    { key: 'login_pass', label: 'Password (blank = keep)', type: 'password',
      revealEndpoint: (id) => `/data/logins/${id}/secret`, revealKey: 'password' },
    { key: 'url', label: 'URL' },
    { key: 'type', label: 'Type' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
    { key: 'is_restricted', label: 'Restricted', type: 'checkbox' },
    { key: 'is_active', label: 'Active', type: 'checkbox' },
];

/** Shared editable logins table. `showUser` for the vendor view; otherwise shows Vendor.
 *  `createEndpoint` (optional) enables "+ Add login". */
export default function LoginsTable({ endpoint, showUser = false, createEndpoint = null }) {
    const [reload, setReload] = useState(0);
    const { loading, data } = useJson(`${endpoint}?_=${reload}`);
    const [edit, setEdit] = useState(null); // { id, initial }
    const [adding, setAdding] = useState(false);

    const openEdit = async (id) => {
        const l = await fetch(`/data/logins/${id}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
        setEdit({ id, initial: l });
    };

    const [flash, setFlash] = useState(null); // id of row that just copied
    const [shown, setShown] = useState(null); // { id, password } — one revealed at a time

    const toast = (id, msg) => { setFlash({ id, msg }); setTimeout(() => setFlash(null), 1200); };
    const copy = async (text, id, msg) => { try { await navigator.clipboard.writeText(text ?? ''); toast(id, msg); } catch { /* ignore */ } };
    const reveal = async (id) => (await (await fetch(`/data/logins/${id}/secret`, { headers: { Accept: 'application/json' } })).json());
    const copyPassword = async (id) => copy((await reveal(id)).password, id, 'Password copied');
    // Show the password inline (same gated + audited endpoint as copy).
    const toggleShow = async (id) => {
        if (shown?.id === id) return setShown(null);
        setShown({ id, password: (await reveal(id)).password ?? '' });
    };
    // Share = text the credentials to the user's cell. On macOS/iOS an sms: link opens Messages.
    const share = async (l) => {
        const s = await reveal(l.id);
        const body = [
            s.name ? `Hi ${s.name.split(' ')[0]},` : 'Hi,', '',
            `Your ${l.login_name} login:`,
            l.login_id ? `Username: ${l.login_id}` : null,
            s.password ? `Password: ${s.password}` : null,
            l.url ? `URL: ${l.url}` : null,
        ].filter((x) => x !== null).join('\n');
        const num = (s.cell || '').replace(/[^0-9+]/g, '');
        if (num) window.location.href = `sms:${num}?&body=${encodeURIComponent(body)}`;
        else copy(body, l.id, 'No cell on file — copied');
    };

    const remove = async (id, name) => {
        if (!confirm(`Delete login "${name || id}"? This can't be undone.`)) return;
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        await fetch(`/data/logins/${id}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf } });
        setReload((r) => r + 1);
    };

    // Name column carries the vendor, so no separate Vendor column here.
    const cols = showUser ? ['User', 'Name', 'Login ID', 'Type'] : ['Name', 'Login ID', 'Type', 'URL'];
    const addButton = createEndpoint && (
        <div className="flex justify-end mb-2">
            <button onClick={() => setAdding(true)} className="inline-flex items-center gap-1 text-sm rounded-md border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800"><PlusIcon /> Add login</button>
        </div>
    );
    const addModal = adding && (
        <RecordModal title="Add login" endpoint={createEndpoint} method="POST" fields={LOGIN_FIELDS}
            initial={{ is_active: true }} onClose={() => setAdding(false)}
            onSaved={() => { setAdding(false); setReload((r) => r + 1); }} />
    );

    if (loading) return <>{addButton}<p className="text-sm text-gray-400 py-6">Loading…</p>{addModal}</>;
    if (!data?.length) return <>{addButton}<p className="text-sm text-gray-400 py-6">No logins.</p>{addModal}</>;

    return (
        <>
            {addButton}
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200">
                        {cols.map((c) => <th key={c} className="py-2 pr-4 font-normal">{c}</th>)}
                        <th className="w-8" />
                    </tr>
                </thead>
                <tbody>
                    {data.map((l) => (
                        <tr key={l.id} onClick={() => openEdit(l.id)} className="group border-b border-gray-50 dark:border-gray-800 cursor-pointer hover:bg-blue-50/40">
                            {showUser && <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{l.user || <span className="text-gray-300">Unassigned</span>}</td>}
                            <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">
                                <span className="inline-flex items-center gap-1">{l.login_name}{l.is_restricted && <Lock />}</span>
                            </td>
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                {l.login_id}
                                {shown?.id === l.id && (
                                    <span className="block font-mono text-xs text-amber-600 dark:text-amber-400">{shown.password || '(no password)'}</span>
                                )}
                            </td>
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{l.type || <span className="text-gray-300">—</span>}</td>
                            {!showUser && <td className="py-2 pr-4">{l.url ? <a href={l.url} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()} className="text-blue-600 hover:underline">link</a> : <span className="text-gray-300">—</span>}</td>}
                            <td className="py-2 pr-2 text-right whitespace-nowrap">
                                {flash?.id === l.id
                                    ? <span className="text-xs text-green-600">{flash.msg}</span>
                                    : (
                                        <span className={`inline-flex items-center gap-2 text-gray-400 ${shown?.id === l.id ? '' : 'opacity-0 group-hover:opacity-100'}`}>
                                            <Act onClick={(e) => { e.stopPropagation(); copy(l.login_id, l.id, 'Username copied'); }} title="Copy username"><CopyIcon /></Act>
                                            <Act onClick={(e) => { e.stopPropagation(); toggleShow(l.id); }} title={shown?.id === l.id ? 'Hide password' : 'Show password'}>{shown?.id === l.id ? <EyeOffIcon /> : <EyeIcon />}</Act>
                                            <Act onClick={(e) => { e.stopPropagation(); copyPassword(l.id); }} title="Copy password"><KeyIcon /></Act>
                                            <Act onClick={(e) => { e.stopPropagation(); share(l); }} title="Text credentials to their cell"><ChatIcon /></Act>
                                            <Act onClick={(e) => { e.stopPropagation(); remove(l.id, l.login_name); }} title="Delete login" hover="hover:text-red-600"><TrashIcon /></Act>
                                        </span>
                                    )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>

            {edit && (
                <RecordModal title="Edit login" endpoint={`/data/logins/${edit.id}`} method="PATCH"
                    fields={LOGIN_FIELDS} initial={edit.initial}
                    onClose={() => setEdit(null)}
                    onSaved={() => { setEdit(null); setReload((r) => r + 1); }} />
            )}
            {addModal}
        </>
    );
}

function Act({ children, onClick, title, hover = 'hover:text-blue-600' }) {
    return <button onClick={onClick} title={title} className={`px-1 leading-none ${hover}`}>{children}</button>;
}

function Lock() {
    return <svg className="h-3.5 w-3.5 text-amber-500" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1a5 5 0 00-5 5v3H6a2 2 0 00-2 2v9a2 2 0 002 2h12a2 2 0 002-2v-9a2 2 0 00-2-2h-1V6a5 5 0 00-5-5zm3 8H9V6a3 3 0 016 0v3z" /></svg>;
}
