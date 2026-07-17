import Field from '@/Components/detail/Field';

const SHARING = {
    personal: 'Personal — one human',
    pooled: 'Pooled — one at a time, reassignable',
    shared: 'Shared — many at once (a mailbox)',
    service: 'Service — runs the system, no human holder',
    breakglass: 'Break glass — sealed emergency access',
};

/**
 * A credential account seen from the account side: what it is, how it's held,
 * who holds it, and the seats it consumes. Person-centric access lives on the
 * staff screen; this is the same data pivoted.
 */
export default function AccountDetail({ a }) {
    return (
        <div className="max-w-3xl">
            {/* The account IS the email/username; the service it's for is the subtitle. */}
            <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{a.login_id || a.login_name}</h2>
            <p className="text-sm text-gray-500 mb-4">
                {a.login_id ? a.login_name : null}
                {a.is_restricted && <span className="ml-2 rounded bg-amber-100 dark:bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400">restricted</span>}
            </p>

            <dl className="grid grid-cols-2 gap-x-8 mb-6">
                <Field label="Sharing" value={SHARING[a.sharing] || a.sharing} />
                <Field label="Type" value={a.type} />
                <Field label="URL" value={a.url} />
                <Field label="Active" value={a.is_active ? 'Yes' : 'No'} />
                <Field label="Notes" value={a.notes} />
            </dl>

            <h3 className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Held by</h3>
            {a.holders?.length ? (
                <div className="mb-6 flex flex-wrap gap-1.5">
                    {a.holders.map((name) => (
                        <span key={name} className="rounded-full bg-blue-50 dark:bg-blue-500/15 px-2.5 py-1 text-xs text-blue-700 dark:text-blue-300">{name}</span>
                    ))}
                </div>
            ) : a.sharing === 'service' ? (
                // No holder is the CORRECT state here — don't nag to assign one.
                <p className="mb-6 text-sm text-gray-400">No one — this account runs the system, it isn't held.</p>
            ) : a.sharing === 'breakglass' ? (
                <p className="mb-6 text-sm text-amber-600 dark:text-amber-400">Sealed — emergency use only. Every reveal is logged.</p>
            ) : (
                <p className="mb-6 text-sm text-gray-400">
                    Nobody{a.sharing === 'pooled' ? ' — an available seat' : ''}. Assign people with Edit.
                </p>
            )}

            <h3 className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Licenses this account consumes</h3>
            {a.licenses?.length ? (
                <ul className="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                    {a.licenses.map((l) => <li key={l.id}>{l.name}</li>)}
                </ul>
            ) : (
                <p className="text-sm text-gray-400">None — a service or infrastructure account.</p>
            )}
        </div>
    );
}
