import { PlusIcon } from "@/Components/Icons";
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import EntityList from '@/Components/EntityList';
import RecordModal from '@/Components/RecordModal';
import { ENTITIES } from '@/entities';

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
    const refetchDetail = () => { if (selectedId) fetch(cfg.detailEndpoint(selectedId), { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setDetail); };

    useEffect(() => { setSelectedId(null); setDetail(null); }, [entity, tenant?.activeId]);
    useEffect(() => {
        if (!cfg.filter?.optionsEndpoint) { setFilterOptions([]); return; }
        fetch(cfg.filter.optionsEndpoint, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setFilterOptions);
    }, [entity]);
    useEffect(() => {
        if (!selectedId) { setDetail(null); return; }
        setDetail(null);
        fetch(cfg.detailEndpoint(selectedId), { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setDetail);
    }, [selectedId]);

    const nav = (
        <EntityList endpoint={cfg.listEndpoint} icon={cfg.icon}
            filter={cfg.filter ? { key: cfg.filter.key, label: cfg.filter.label, options: filterOptions } : null}
            sortOptions={cfg.sort || []} selectedId={selectedId} onSelect={setSelectedId} onStats={setStats} reloadKey={reloadKey} />
    );

    const detailPane = (
        <div className="h-full flex flex-col">
            <div className="flex items-center justify-between mb-4">
                <h1 className="text-xl font-semibold text-gray-800 dark:text-gray-100">{title}</h1>
                <div className="flex items-center gap-2">
                    {cfg.edit && detail && (
                        <button onClick={() => setEditing(true)} className="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Edit</button>
                    )}
                    {cfg.add && (
                        <button onClick={() => setAdding(true)} className="flex items-center gap-1 rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700"><PlusIcon /> {cfg.add.title}</button>
                    )}
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
                    onSaved={(c) => { setAdding(false); setListVersion((v) => v + 1); if (c?.id) setSelectedId(c.id); }} />
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
