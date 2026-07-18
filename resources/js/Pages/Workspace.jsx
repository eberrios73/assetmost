import { Head, usePage } from '@inertiajs/react';
import AddButton from '@/Components/ui/AddButton';
import { useEffect, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import EntityList from '@/Components/EntityList';
import RecordModal from '@/Components/RecordModal';
import PasswordGate from '@/Components/ui/PasswordGate';
import OnboardingSetup from '@/Components/OnboardingSetup';
import { ENTITIES, GROUPS } from '@/entities';
import { getLast, setLast } from '@/lib/lastView';

const ONB_STEPS = ['User info', 'Services', 'Active Directory', 'Hardware', 'Software', 'Security'];

export default function Workspace({ group }) {
    const g = GROUPS[group];
    const { tenant } = usePage().props;
    const [tabKey, setTabKey] = useState(g.tabs[0].key);
    const tab = g.tabs.find((t) => t.key === tabKey) || g.tabs[0];
    const entity = tab.entity ? ENTITIES[tab.entity] : null;

    const [selectedId, setSelectedId] = useState(null);
    const [detail, setDetail] = useState(null);
    const [filterOptions, setFilterOptions] = useState([]);
    const [stats, setStats] = useState({ shown: 0, total: 0 });
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState(false);
    const [listVersion, setListVersion] = useState(0);
    const [step, setStep] = useState(0);
    // Guarded tabs (Accounts) re-prompt on EVERY entry — switching away re-locks.
    const [unlocked, setUnlocked] = useState(false);
    // Onboarding tabs are filtered views over the workflow docs: fetch the group's
    // slice (people|device) and let the left-column list drive the editor on the right.
    const [workflows, setWorkflows] = useState([]);
    const [wfId, setWfId] = useState(null);
    const [wfVersion, setWfVersion] = useState(0);
    useEffect(() => { setUnlocked(false); setWfId(null); }, [tabKey]);
    useEffect(() => {
        if (tab.view !== 'onboarding') return;
        fetch(`/data/workflows?type=${tab.wtype}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : []))
            .then((d) => setWorkflows(Array.isArray(d) ? d : []))
            .catch(() => setWorkflows([]));
    }, [tabKey, group, tenant?.activeId, wfVersion]);

    const refetchDetail = () => {
        if (entity && selectedId) fetch(entity.detailEndpoint(selectedId), { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setDetail);
    };

    const activeCompany = tenant?.companies?.find((c) => c.id === tenant?.activeId)?.name;
    const reloadKey = `${group}:${tabKey}:${tenant?.activeId ?? 'all'}:${listVersion}`;
    const selScope = `sel:${group}:${tabKey}:${tenant?.activeId ?? 'all'}`;

    // select a record and remember it for this tab/company
    const chooseId = (id) => { setSelectedId(id); setLast(selScope, id); };
    const chooseTab = (key) => { setTabKey(key); setLast(`tab:${group}`, key); };

    // returning to a section restores its last sub-tab…
    useEffect(() => {
        const saved = getLast(`tab:${group}`);
        setTabKey(g.tabs.some((t) => t.key === saved) ? saved : g.tabs[0].key);
    }, [group]);

    // …and the last record you were viewing in it
    useEffect(() => { setDetail(null); setSelectedId(getLast(selScope)); }, [selScope]);

    useEffect(() => {
        if (!entity?.filter?.optionsEndpoint) { setFilterOptions([]); return; }
        fetch(entity.filter.optionsEndpoint, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setFilterOptions);
    }, [tabKey]);

    useEffect(() => {
        if (!entity || !selectedId) { setDetail(null); return; }
        setDetail(null);
        fetch(entity.detailEndpoint(selectedId), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => { if (d) setDetail(d); else { setSelectedId(null); setLast(selScope, null); } }); // remembered record was deleted
    }, [selectedId]);

    const subTabs = (
        <div className="flex border-b border-gray-200 dark:border-gray-800 px-2 pt-1">
            {g.tabs.map((t) => (
                <button key={t.key} onClick={() => chooseTab(t.key)}
                    className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${tabKey === t.key ? 'text-blue-600 border-blue-600' : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'}`}>
                    {t.label}
                </button>
            ))}
        </div>
    );

    let listContent, detailContent, footerLeft, footerRight;
    if (entity?.guard && !unlocked) {
        listContent = <PasswordGate reason={entity.guard.reason} onUnlocked={() => setUnlocked(true)} />;
        detailContent = <Center>Unlock to view {tab.label.toLowerCase()}</Center>;
        footerLeft = `${tab.label} — locked`;
    } else if (entity) {
        listContent = (
            <EntityList endpoint={entity.listEndpoint} icon={entity.icon}
                filter={entity.filter ? { key: entity.filter.key, label: entity.filter.label, options: filterOptions } : null}
                sortOptions={entity.sort || []} selectedId={selectedId} onSelect={chooseId} onStats={setStats} reloadKey={reloadKey} />
        );
        // render(record, refetch) — refetch lets a detail screen that edits its own
        // children (rooms inside a location) refresh itself without a page reload.
        detailContent = detail ? entity.render(detail, refetchDetail) : <Center>Select {entity.noun} on the left</Center>;
        footerLeft = `Records Displayed: ${stats.shown} of ${stats.total}`;
        footerRight = (
            <span className="flex gap-4 text-gray-400">
                <span>{entity.idLabel}: {detail?.id ?? ''}</span>
                <span>Updated: {fmt(detail?.updated_at)}</span>
                <span>Created: {fmt(detail?.created_at)}</span>
            </span>
        );
    } else if (tab.view === 'onboarding') {
        // Only ACTIVE workflows show; device baselines (form-factor ones) nest under
        // "Device Setup", the rest (Endpoint protection, the people workflows) sit flat.
        // In All-companies mode every company keeps its own set — group by company so
        // the pairs read as what they are, not duplicates.
        const activeWf = workflows.filter((w) => w.active);
        const sel = activeWf.find((w) => w.id === wfId)
            || activeWf.find((w) => w.form_factor) || activeWf[0] || null;
        const wfBtn = (w) => (
            <button key={w.id} onClick={() => setWfId(w.id)}
                className={`w-full rounded-md px-3 py-2 text-left text-sm ${sel?.id === w.id ? 'bg-blue-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'}`}>
                {w.title}
            </button>
        );
        const companyIds = [...new Set(activeWf.map((w) => w.company_id))];
        const section = (list) => {
            const baselines = tab.wtype === 'device' ? list.filter((w) => w.form_factor) : [];
            const flat = tab.wtype === 'device' ? list.filter((w) => !w.form_factor) : list;
            return (
                <>
                    {baselines.length > 0 && (
                        <>
                            <p className="px-3 pt-1 pb-0.5 text-xs uppercase tracking-wide text-gray-400">Device Setup</p>
                            <div className="ml-3 space-y-1">{baselines.map(wfBtn)}</div>
                        </>
                    )}
                    {flat.map(wfBtn)}
                </>
            );
        };
        listContent = (
            <div className="p-3 space-y-1">
                {companyIds.length > 1
                    ? companyIds.map((cid) => {
                        const list = activeWf.filter((w) => w.company_id === cid);
                        return (
                            <div key={cid} className="space-y-1">
                                <p className="px-3 pt-2 pb-0.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{list[0]?.company || `Company ${cid}`}</p>
                                {section(list)}
                            </div>
                        );
                    })
                    : section(activeWf)}
            </div>
        );
        detailContent = sel
            ? <OnboardingSetup key={sel.id} workflow={sel} onChanged={() => setWfVersion((v) => v + 1)} />
            : <Center>No active workflows here yet.</Center>;
        footerLeft = sel ? `${sel.title} — workflow` : 'Workflows';
    } else {
        listContent = <div className="p-4 text-sm text-gray-400">{g.title}</div>;
        detailContent = <ComingSoon group={group} />;
        footerLeft = g.title;
    }

    const nav = <div className="flex flex-col h-full">{subTabs}<div className="flex-1 min-h-0">{listContent}</div></div>;

    const detailPane = (
        <div className="h-full flex flex-col">
            <div className="flex items-center justify-between mb-4">
                <h1 className="text-xl font-semibold text-gray-800 dark:text-gray-100">{tab.label}{activeCompany && entity ? ` — ${activeCompany}` : ''}</h1>
                <div className="flex items-center gap-2">
                    {entity?.edit && detail && (
                        <button onClick={() => setEditing(true)} className="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Edit</button>
                    )}
                    {entity?.add && <AddButton label={entity.add.title} onClick={() => setAdding(true)} />}
                </div>
            </div>
            <div className="flex-1 min-h-0">{detailContent}</div>
        </div>
    );

    const footer = <div className="flex w-full justify-between"><span>{footerLeft}</span>{footerRight}</div>;

    return (
        <>
            <Head title={g.title} />
            <AppShell active={group} nav={nav} detail={detailPane} footer={footer} />
            {adding && entity?.add && (
                <RecordModal title={entity.add.title} endpoint={entity.add.endpoint} method="POST" fields={entity.add.fields}
                    onClose={() => setAdding(false)}
                    onSaved={(c) => { setAdding(false); setListVersion((v) => v + 1); if (c?.id) chooseId(c.id); }} />
            )}
            {editing && entity?.edit && detail && (
                <RecordModal title={`Edit ${tab.label}`} endpoint={entity.detailEndpoint(selectedId)} method="PATCH"
                    fields={entity.edit.fields} initial={detail}
                    onClose={() => setEditing(false)}
                    onSaved={() => { setEditing(false); setListVersion((v) => v + 1); refetchDetail(); }} />
            )}
        </>
    );
}

function fmt(v) { if (!v) return ''; try { return new Date(v).toLocaleDateString(); } catch { return ''; } }
function Center({ children }) { return <div className="h-full flex items-center justify-center text-gray-400 text-sm">{children}</div>; }

function OnboardingStep({ step, setStep }) {
    return (
        <div className="max-w-2xl">
            <div className="rounded-lg border border-gray-200 bg-white p-6 min-h-[200px] text-sm text-gray-500">
                The <strong>{ONB_STEPS[step]}</strong> step form goes here — driven by a configurable service catalog, not hardcoded per-service blocks.
            </div>
            <div className="mt-4 flex justify-between">
                <button disabled={step === 0} onClick={() => setStep((s) => s - 1)} className="px-4 py-2 text-sm rounded-md border border-gray-200 text-gray-600 disabled:opacity-40">Back</button>
                <button disabled={step === ONB_STEPS.length - 1} onClick={() => setStep((s) => s + 1)} className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white disabled:opacity-40">Next</button>
            </div>
        </div>
    );
}

function ComingSoon({ group }) {
    const copy = group === 'tasks'
        ? { title: 'Tasks', body: 'Integration point for your task database — tickets, assignments, and workflows alongside assets and people.' }
        : { title: 'Documentation', body: 'The documentation canvas — runbooks, SOPs, and per-company docs, sharing the same company tenant.' };
    return (
        <div className="max-w-2xl">
            <div className="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center">
                <div className="text-lg font-medium text-gray-700 mb-1">{copy.title}</div>
                <p className="text-sm text-gray-500">{copy.body}</p>
                <span className="inline-block mt-4 text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-500">Ready to integrate</span>
            </div>
        </div>
    );
}
