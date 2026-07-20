import Field from '@/Components/detail/Field';
import DataTable from '@/Components/ui/DataTable';

const SHARING = {
    pooled: 'Pooled — one at a time, reassignable',
    shared: 'Shared — many at once (a mailbox)',
    service: 'Service — runs the system, no human holder',
    breakglass: 'Break glass — sealed emergency access',
};

/**
 * A FLOATING account: ONE credential identity (artist001, info@, ITAdmin) used
 * across many services. The services are uses of the credential, not more
 * accounts — which is why they're a table inside this screen, and why the
 * assignment (who currently holds the credential) lives here, not on them.
 */
export default function AccountDetail({ a }) {
    const serviceCols = [
        // One column for both: a use of the credential is a service (Adobe) OR a
        // device (Mail_Arch_Srv) — `target` is whichever this login points at.
        { key: 'target', label: 'Service / Device', width: '30%', className: 'text-gray-800 dark:text-gray-200',
          render: (s) => (
              <span className="inline-flex items-center gap-1.5">
                  {s.target}
                  {s.is_device && <span className="rounded bg-gray-100 dark:bg-gray-800 px-1 py-0.5 text-[10px] uppercase tracking-wide text-gray-500">device</span>}
              </span>
          ) },
        { key: 'vendor', label: 'Vendor', width: '22%' },
        { key: 'type', label: 'Type', width: '18%' },
        { key: 'url', label: 'URL', width: '15%',
          render: (s) => s.url ? <a href={s.url} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()} className="text-blue-600 hover:underline">link</a> : <span className="text-gray-300">—</span> },
        { key: 'is_active', label: 'Active', width: '15%', sortValue: (s) => (s.is_active ? 1 : 0),
          render: (s) => s.is_active ? 'Yes' : <span className="text-gray-400">No</span> },
    ];

    return (
        <div className="max-w-3xl">
            <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{a.identifier}</h2>
            <p className="text-sm text-gray-500 mb-4">
                {SHARING[a.sharing] || a.sharing}
                {a.sharing === 'breakglass' && <span className="ml-2 rounded bg-amber-100 dark:bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400">sealed</span>}
            </p>

            <dl className="grid grid-cols-2 gap-x-8 mb-6">
                <Field label="Active" value={a.is_active ? 'Yes' : 'No'} />
                <Field label="Notes" value={a.notes} />
            </dl>

            <h3 className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Assigned to</h3>
            {a.holders?.length ? (
                <div className="mb-6 flex flex-wrap gap-1.5">
                    {a.holders.map((name) => (
                        <span key={name} className="rounded-full bg-blue-50 dark:bg-blue-500/15 px-2.5 py-1 text-xs text-blue-700 dark:text-blue-300">{name}</span>
                    ))}
                </div>
            ) : a.sharing === 'service' ? (
                <p className="mb-6 text-sm text-gray-400">No one — this account runs the system, it isn't held.</p>
            ) : a.sharing === 'breakglass' ? (
                <p className="mb-6 text-sm text-amber-600 dark:text-amber-400">Sealed — emergency use only. Every reveal is logged.</p>
            ) : (
                <p className="mb-6 text-sm text-gray-400">
                    Unassigned{a.sharing === 'pooled' ? ' — available to hand out' : ''}. Assign someone with Edit.
                </p>
            )}

            <h3 className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Used in these services</h3>
            <DataTable columns={serviceCols} rows={a.services || []}
                emptyText="No service logins linked to this credential yet." searchable={(a.services || []).length > 5} />
        </div>
    );
}
