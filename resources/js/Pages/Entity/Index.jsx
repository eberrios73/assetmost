import { PlusIcon } from "@/Components/Icons";
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import EntityList from '@/Components/EntityList';
import RecordModal from '@/Components/RecordModal';
import { ENTITIES } from '@/entities';
import { getLast, setLast } from '@/lib/lastView';

/** Standalone single-entity page (used by Companies). Groups use Workspace. */
export default function Index({ entity, title }) {
    const cfg = ENTITIES[entity];
    const { tenant } = usePage().props;
    const [selectedId, setSelectedId] = useState(null);
    const [detail, setDetail] = useState(null);
    const [filterOptions, setFilterOptions] = useState([]);
    const [stats, setStats] = useState({ shown: 0, total: 0 });
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState(false);
    const [listVersion, setListVersion] = useState(0);

    const reloadKey = `${entity}:${tenant?.activeId ?? 'all'}:${listVersion}`;
    const selScope = `sel:${entity}:${tenant?.activeId ?? 'all'}`;
    const chooseId = (id) => { setSelectedId(id); setLast(selScope, id); };
    const refetchDetail = () => { if (selectedId) fetch(cfg.detailEndpoint(selectedId), { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setDetail); };

    // restore the last record viewed for this entity/company
    useEffect(() => { setDetail(null); setSelectedId(getLast(selScope)); }, [selScope]);
    useEffect(() => {
        if (!cfg.filter?.optionsEndpoint) { setFilterOptions([]); return; }
        fetch(cfg.filter.optionsEndpoint, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setFilterOptions);
    }, [entity]);
    useEffect(() => {
        if (!selectedId) { setDetail(null); return; }
        setDetail(null);
        fetch(cfg.detailEndpoint(selectedId), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => { if (d) setDetail(d); else { setSelectedId(null); setLast(selScope, null); } });
    }, [selectedId]);

    const nav = (
        <EntityList endpoint={cfg.listEndpoint} icon={cfg.icon}
            filter={cfg.filter ? { key: cfg.filter.key, label: cfg.filter.label, options: filterOptions } : null}
            sortOptions={cfg.sort || []} selectedId={selectedId} onSelect={chooseId} onStats={setStats} reloadKey={reloadKey} />
    );

    // Tenant cap (companies only). Use the live list total for the current count.
    const isTenants = entity === 'companies';
    const cap = tenant?.maxTenants ?? null;
    const used = tenant?.tenantCount ?? stats.total;   // authoritative total (list is scoped to allowed)
    const atCap = isTenants && cap != null && used >= cap;

    const detailPane = (
        <div className="h-full flex flex-col">
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-baseline gap-3">
                    <h1 className="text-xl font-semibold text-gray-800 dark:text-gray-100">{title}</h1>
                    {isTenants && cap != null && (
                        <span className={`text-sm ${atCap ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-gray-400'}`}>
                            Tenants: {used} / {cap}
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {cfg.edit && detail && (
                        <button onClick={() => setEditing(true)} className="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Edit</button>
                    )}
                    {cfg.add && (atCap ? (
                        <span className="flex items-center gap-2">
                            <span className="hidden sm:inline text-xs text-amber-600 dark:text-amber-400">Enterprise adds tenants at ${tenant?.extraTenantPrice ?? 30}/yr</span>
                            <button disabled title={`Tenant limit reached (${cap}). Beyond ${cap} is Enterprise.`}
                                className="flex items-center gap-1 rounded-md bg-gray-300 dark:bg-gray-700 px-3 py-1.5 text-sm text-white/80 cursor-not-allowed"><PlusIcon /> {cfg.add.title}</button>
                        </span>
                    ) : (
                        <button onClick={() => setAdding(true)} className="flex items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700"><PlusIcon /> {cfg.add.title}</button>
                    ))}
                </div>
            </div>
            <div className="flex-1 min-h-0">
                {detail ? cfg.render(detail) : <div className="h-full flex items-center justify-center text-gray-400 text-sm">Select {cfg.noun} on the left</div>}
            </div>
        </div>
    );

    const footer = (
        <div className="flex w-full justify-between">
            <span>Records Displayed: {stats.shown} of {stats.total}</span>
            <span className="flex gap-4 text-gray-400">
                <span>{cfg.idLabel}: {detail?.id ?? ''}</span>
                <span>Updated: {detail?.updated_at ? new Date(detail.updated_at).toLocaleDateString() : ''}</span>
                <span>Created: {detail?.created_at ? new Date(detail.created_at).toLocaleDateString() : ''}</span>
            </span>
        </div>
    );

    return (
        <>
            <Head title={title} />
            <AppShell nav={nav} detail={detailPane} footer={footer} />
            {adding && cfg.add && (
                <RecordModal title={cfg.add.title} endpoint={cfg.add.endpoint} method="POST" fields={cfg.add.fields}
                    onClose={() => setAdding(false)}
                    onSaved={(c) => { setAdding(false); setListVersion((v) => v + 1); if (c?.id) chooseId(c.id); if (isTenants) router.reload({ only: ['tenant'] }); }} />
            )}
            {editing && cfg.edit && detail && (
                <RecordModal title={`Edit ${title}`} endpoint={cfg.detailEndpoint(selectedId)} method="PATCH"
                    fields={cfg.edit.fields} initial={detail}
                    onClose={() => setEditing(false)}
                    onSaved={() => { setEditing(false); setListVersion((v) => v + 1); refetchDetail(); }} />
            )}
        </>
    );
}
