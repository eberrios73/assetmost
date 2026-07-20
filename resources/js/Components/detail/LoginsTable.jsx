import { useState } from 'react';
import { useJson } from '@/Components/detail/Field';
import RecordModal from '@/Components/RecordModal';
import DataTable from '@/Components/ui/DataTable';
import { CopyIcon, KeyIcon, ChatIcon, TrashIcon, EyeIcon, EyeOffIcon } from '@/Components/Icons';

const LOGIN_FIELDS = [
    { key: 'vendor_id', label: 'Vendor', type: 'select-search', optionsEndpoint: '/data/vendor-options' },
    { key: 'login_name', label: 'Name', required: true },
    { key: 'login_id', label: 'Login ID' },
    { key: 'login_pass', label: 'Password (blank = keep)', type: 'password',
      revealEndpoint: (id) => `/data/logins/${id}/secret`, revealKey: 'password' },
    // Who holds this credential — many allowed (shared mailbox, pooled seat).
    { key: 'holder_ids', label: 'Assigned to', type: 'multi-select-search', optionsEndpoint: '/data/person-options', pickPlaceholder: 'Search people to add…' },
    { key: 'url', label: 'URL' },
    { key: 'type', label: 'Type' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
    { key: 'is_restricted', label: 'Restricted', type: 'checkbox' },
    { key: 'is_active', label: 'Active', type: 'checkbox' },
];

/** Shared logins table — one DataTable like every other screen. `showUser` for the
 *  vendor view; `createEndpoint` (optional) enables the shared Add button.
 *  `presetHolderIds`: adding from a person's screen pre-assigns to that person.
 *  `onChanged`: tell the parent after add/edit/delete (tab counts and the like). */
export default function LoginsTable({ endpoint, showUser = false, createEndpoint = null, presetHolderIds = null, onChanged = null }) {
    const [reload, setReload] = useState(0);
    const { loading, data } = useJson(`${endpoint}?_=${reload}`);
    const [edit, setEdit] = useState(null);
    const [adding, setAdding] = useState(false);
    const [flash, setFlash] = useState(null);   // { id, msg } after a copy
    const [shown, setShown] = useState(null);   // { id, password } — one at a time

    const openEdit = async (r) => {
        const l = await fetch(`/data/logins/${r.id}`, { headers: { Accept: 'application/json' } }).then((x) => x.json());
        setEdit({ id: r.id, initial: l });
    };

    const toast = (id, msg) => { setFlash({ id, msg }); setTimeout(() => setFlash(null), 1200); };
    const copy = async (text, id, msg) => { try { await navigator.clipboard.writeText(text ?? ''); toast(id, msg); } catch { /* ignore */ } };
    const reveal = async (id) => (await (await fetch(`/data/logins/${id}/secret`, { headers: { Accept: 'application/json' } })).json());
    const copyPassword = async (id) => copy((await reveal(id)).password, id, 'Password copied');
    const toggleShow = async (id) => {
        if (shown?.id === id) return setShown(null);
        setShown({ id, password: (await reveal(id)).password ?? '' });
    };
    // Share = text the credentials to the holder's cell (sms: opens Messages on macOS/iOS).
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
        onChanged?.();
    };

    if (loading) return <p className="text-sm text-gray-400 py-6">Loading…</p>;

    const columns = [
        ...(showUser ? [{ key: 'user', label: 'User', width: '18%', className: 'text-gray-800 dark:text-gray-200',
            render: (l) => l.user || <span className="text-gray-300">Unassigned</span> }] : []),
        { key: 'login_name', label: 'Name', width: showUser ? '20%' : '24%', className: 'text-gray-800 dark:text-gray-200',
          render: (l) => <span className="inline-flex items-center gap-1">{l.login_name}{l.is_restricted && <Lock />}</span> },
        { key: 'login_id', label: 'Login ID', width: '26%',
          render: (l) => (
              <>
                  {l.login_id || <span className="text-gray-300">—</span>}
                  {shown?.id === l.id && (
                      <span className="block font-mono text-xs text-amber-600 dark:text-amber-400">{shown.password || '(no password)'}</span>
                  )}
              </>
          ) },
        { key: 'type', label: 'Type', width: '12%' },
        ...(!showUser ? [{ key: 'url', label: 'URL', width: '10%',
            render: (l) => l.url ? <a href={l.url} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()} className="text-blue-600 hover:underline">link</a> : <span className="text-gray-300">—</span> }] : []),
        { key: '_actions', label: '', width: '14%', sortValue: () => '',
          render: (l) => flash?.id === l.id
              ? <span className="text-xs text-green-600">{flash.msg}</span>
              : (
                  <span className="inline-flex items-center gap-2 text-gray-400" onClick={(e) => e.stopPropagation()}>
                      <Act onClick={() => copy(l.login_id, l.id, 'Username copied')} title="Copy username"><CopyIcon /></Act>
                      <Act onClick={() => toggleShow(l.id)} title={shown?.id === l.id ? 'Hide password' : 'Show password'}>{shown?.id === l.id ? <EyeOffIcon /> : <EyeIcon />}</Act>
                      <Act onClick={() => copyPassword(l.id)} title="Copy password"><KeyIcon /></Act>
                      <Act onClick={() => share(l)} title="Text credentials to their cell"><ChatIcon /></Act>
                      <Act onClick={() => remove(l.id, l.login_name)} title="Delete login" hover="hover:text-red-600"><TrashIcon /></Act>
                  </span>
              ) },
    ];

    return (
        <>
            <DataTable columns={columns} rows={data || []} onRowClick={openEdit}
                addLabel={createEndpoint ? 'Add login' : null} onAdd={createEndpoint ? () => setAdding(true) : null}
                emptyText="No logins." />

            {adding && createEndpoint && (
                <RecordModal title="Add login" endpoint={createEndpoint} method="POST" fields={LOGIN_FIELDS}
                    initial={{ is_active: true, is_restricted: false, ...(presetHolderIds ? { holder_ids: presetHolderIds } : {}) }}
                    onClose={() => setAdding(false)}
                    onSaved={() => { setAdding(false); setReload((r) => r + 1); onChanged?.(); }} />
            )}
            {edit && (
                <RecordModal title="Edit login" endpoint={`/data/logins/${edit.id}`} method="PATCH"
                    fields={LOGIN_FIELDS} initial={edit.initial}
                    onClose={() => setEdit(null)}
                    onSaved={() => { setEdit(null); setReload((r) => r + 1); onChanged?.(); }} />
            )}
        </>
    );
}

function Act({ children, onClick, title, hover = 'hover:text-blue-600' }) {
    return <button onClick={onClick} title={title} className={`px-1 leading-none ${hover}`}>{children}</button>;
}

function Lock() {
    return <svg className="h-3.5 w-3.5 text-amber-500" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1a5 5 0 00-5 5v3H6a2 2 0 00-2 2v9a2 2 0 002 2h12a2 2 0 002-2v-9a2 2 0 00-2-2h-1V6a5 5 0 00-5-5zm3 8H9V6a3 3 0 016 0v3z" /></svg>;
}
