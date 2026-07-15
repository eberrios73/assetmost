import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import SearchSelect from '@/Components/SearchSelect';
import { TrashIcon } from '@/Components/Icons';

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const api = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then((r) => (r.status === 204 ? {} : r.json()));

const PRI = ['·', 'Low', 'Med', 'High'];
const PRI_DOT = ['bg-gray-300', 'bg-blue-500', 'bg-amber-500', 'bg-red-500'];
const STATUS_STYLE = {
    Proposed: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    Approved: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
    'In progress': 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
    'On hold': 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    Blocked: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
    Done: 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-400',
};

// --- local week math (Monday-start), matching the backend ---
const parseYmd = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const mondayOf = (d) => { const x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; };
const fmt = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
function isoWeek(d) {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    t.setUTCDate(t.getUTCDate() - ((t.getUTCDay() + 6) % 7) + 3);
    const f = new Date(Date.UTC(t.getUTCFullYear(), 0, 4));
    f.setUTCDate(f.getUTCDate() - ((f.getUTCDay() + 6) % 7) + 3);
    return 1 + Math.round((t - f) / 6048e5);
}

export default function Index() {
    const [tasks, setTasks] = useState([]);
    const [statuses, setStatuses] = useState([]);
    const [currentWeek, setCurrentWeek] = useState(ymd(mondayOf(new Date())));
    const [mode, setMode] = useState('tasks');       // 'tasks' | 'projects'
    const [view, setView] = useState(ymd(mondayOf(new Date()))); // viewed week (Monday)
    const [selectedId, setSelectedId] = useState(null);
    const [detail, setDetail] = useState(null);
    const [status, setStatus] = useState('');
    const timers = useRef({});

    const load = () => api('/data/tasks').then((r) => { setTasks(r.tasks); setStatuses(r.statuses); setCurrentWeek(r.currentWeek); });
    useEffect(() => { load(); }, []);
    useEffect(() => {
        if (!selectedId) { setDetail(null); return; }
        api(`/data/tasks/${selectedId}`).then(setDetail);
    }, [selectedId]);

    const flash = (msg = 'Saved') => { setStatus(msg); clearTimeout(timers.current._s); timers.current._s = setTimeout(() => setStatus(''), 1200); };

    // optimistic patch; reloads so rollover / section moves settle
    const patch = (id, changes, { debounce, reload = true } = {}) => {
        setTasks((ts) => ts.map((t) => (t.id === id ? { ...t, ...changes } : t)));
        setDetail((d) => (d && d.id === id ? { ...d, ...changes } : d));
        const send = () => { setStatus('Saving…'); api(`/data/tasks/${id}`, 'PATCH', changes).then(() => { flash(); if (reload) load(); }); };
        if (debounce) { clearTimeout(timers.current[id]); timers.current[id] = setTimeout(send, 500); }
        else send();
    };

    const addTask = async (title, isProject = false) => {
        const week = isProject ? currentWeek : view;
        const body = { title, week, ...(isProject ? { is_project: true, status: 'Proposed' } : {}) };
        const { id } = await api('/data/tasks', 'POST', body);
        await load();
        setSelectedId(id);
    };
    const del = async () => {
        if (!confirm('Delete this item?')) return;
        await api(`/data/tasks/${selectedId}`, 'DELETE');
        setSelectedId(null); load();
    };
    // flip is_project → the record moves to the other view; deselect it here
    const flip = (id, toProject) => { patch(id, { is_project: toProject }); setSelectedId(null); };

    // ---- Tasks view: this week's open tasks + a completed archive (viewed + 3 prior) ----
    const nonProjects = useMemo(() => tasks.filter((t) => !t.is_project), [tasks]);
    const current = useMemo(
        () => nonProjects.filter((t) => t.week === view && !t.done).sort((a, b) => (b.pri - a.pri) || (a.ord - b.ord)),
        [nonProjects, view],
    );
    const completedGroups = useMemo(() => {
        const out = [];
        for (let i = 0; i < 4; i++) {
            const wk = ymd(addDays(parseYmd(view), -7 * i));
            const items = nonProjects.filter((t) => t.week === wk && t.done).sort((a, b) => a.ord - b.ord);
            if (items.length) out.push({ wk, items });
        }
        return out;
    }, [nonProjects, view]);
    const avg = current.length || completedGroups[0]?.items.length
        ? Math.round(
            nonProjects.filter((t) => t.week === view).reduce((s, t) => s + (t.pct || 0), 0)
            / Math.max(1, nonProjects.filter((t) => t.week === view).length))
        : 0;

    const projects = useMemo(
        () => tasks.filter((t) => t.is_project).sort((a, b) => a.ord - b.ord),
        [tasks],
    );

    const viewDate = parseYmd(view);
    const weekRange = `${fmt(viewDate)} – ${fmt(addDays(viewDate, 6))}`;
    const weekNum = `Week ${isoWeek(viewDate)} · ${viewDate.getFullYear()}`;

    const tabs = (
        <div className="flex border-b border-gray-200 dark:border-gray-800">
            {['tasks', 'projects'].map((m) => (
                <button key={m} onClick={() => { setMode(m); setSelectedId(null); }}
                    className={`px-4 py-2.5 text-sm font-medium border-b-2 -mb-px capitalize ${mode === m ? 'text-blue-600 border-blue-600' : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'}`}>
                    {m}{m === 'projects' && projects.length ? ` (${projects.length})` : ''}
                </button>
            ))}
        </div>
    );

    const nav = (
        <div className="flex flex-col h-full">
            {tabs}
            {mode === 'tasks' ? (
                <>
                    <div className="px-3 pt-3 flex items-center gap-1.5 text-sm">
                        <NavBtn onClick={() => setView(ymd(addDays(viewDate, -7)))}>‹ Prev</NavBtn>
                        <NavBtn onClick={() => setView(currentWeek)} active={view === currentWeek}>This week</NavBtn>
                        <NavBtn onClick={() => setView(ymd(addDays(viewDate, 7)))}>Next ›</NavBtn>
                    </div>
                    <div className="px-3 pt-2 pb-1">
                        <div className="text-base font-semibold text-gray-800 dark:text-gray-100">{weekRange}</div>
                        <div className="text-xs uppercase tracking-wide text-gray-400">{weekNum}</div>
                    </div>
                    <AddBar placeholder="Add a task and press Enter…" onAdd={(v) => addTask(v)} />
                    <div className="flex-1 overflow-y-auto">
                        <SectionHeader label="Current" right={current.length || completedGroups.length ? `${avg}% complete` : ''} />
                        {current.length === 0 && <Empty>{completedGroups.length ? 'All clear for this week.' : 'No tasks yet. Add one above.'}</Empty>}
                        {current.map((t) => (
                            <TaskRow key={t.id} t={t} selected={selectedId === t.id}
                                onSelect={() => setSelectedId(t.id)} onToggle={(done) => patch(t.id, { done })} />
                        ))}
                        {completedGroups.length > 0 && <SectionHeader label="Completed" />}
                        {completedGroups.map((g) => (
                            <div key={g.wk}>
                                <SubHeader label={weekLabel(g.wk, currentWeek)} right={`${g.items.length} done`} />
                                {g.items.map((t) => (
                                    <TaskRow key={t.id} t={t} selected={selectedId === t.id}
                                        onSelect={() => setSelectedId(t.id)} onToggle={(done) => patch(t.id, { done })} />
                                ))}
                            </div>
                        ))}
                    </div>
                </>
            ) : (
                <>
                    <AddBar placeholder="Add a project and press Enter…" onAdd={(v) => addTask(v, true)} />
                    <div className="flex-1 overflow-y-auto">
                        {projects.length === 0 && <Empty>No projects yet. Add one, or flag a task as a project.</Empty>}
                        {projects.map((p) => (
                            <ProjectRow key={p.id} p={p} selected={selectedId === p.id} onSelect={() => setSelectedId(p.id)} />
                        ))}
                    </div>
                </>
            )}
        </div>
    );

    const detailPane = !detail ? (
        <div className="h-full flex items-center justify-center text-gray-400 text-sm">
            {mode === 'tasks' ? 'Select or add a task' : 'Select or add a project'}
        </div>
    ) : detail.is_project ? (
        <ProjectDetail key={detail.id} t={detail} statuses={statuses} patch={patch} onDelete={del} onMoveToTasks={() => flip(detail.id, false)} />
    ) : (
        <TaskDetail key={detail.id} t={detail} patch={patch} onDelete={del} onMakeProject={() => flip(detail.id, true)} currentWeek={currentWeek} />
    );

    const doneCount = nonProjects.filter((t) => t.done).length;
    const footer = (
        <div className="flex w-full justify-between">
            <span>{mode === 'tasks' ? `Tasks — ${doneCount} of ${nonProjects.length} done` : `Projects — ${projects.length}`}</span>
            <span className="text-gray-400">{status}</span>
        </div>
    );

    return (
        <>
            <Head title="Tasks" />
            <AppShell active="tasks" nav={nav} detail={detailPane} footer={footer} />
        </>
    );
}

// week is a Monday; label like "Jul 13 – Jul 19 · W29", prefixed "This week" when current.
function weekLabel(wk, currentWeek) {
    const d = parseYmd(wk);
    const base = `${fmt(d)} – ${fmt(addDays(d, 6))} · W${isoWeek(d)}`;
    return wk === currentWeek ? `This week · ${base}` : base;
}

function NavBtn({ children, onClick, active }) {
    return (
        <button onClick={onClick}
            className={`rounded-md border px-2.5 py-1 text-xs ${active ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-gray-500'}`}>
            {children}
        </button>
    );
}

function AddBar({ placeholder, onAdd }) {
    const [v, setV] = useState('');
    return (
        <div className="px-3 py-2">
            <input value={v} onChange={(e) => setV(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && v.trim()) { onAdd(v.trim()); setV(''); } }}
                placeholder={placeholder}
                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500 placeholder-gray-400" />
        </div>
    );
}

function SectionHeader({ label, right }) {
    return (
        <div className="flex items-center justify-between px-3 py-1.5 bg-gray-100 dark:bg-gray-800/70 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 border-y border-gray-200 dark:border-gray-800">
            <span>{label}</span>{right && <span className="text-blue-600 dark:text-blue-400 normal-case tracking-normal">{right}</span>}
        </div>
    );
}
function SubHeader({ label, right }) {
    return (
        <div className="flex items-center justify-between px-3 py-1 pl-6 bg-gray-50 dark:bg-gray-900/60 text-xs text-gray-400 border-b border-gray-100 dark:border-gray-800">
            <span className="uppercase tracking-wide">{label}</span>{right && <span>{right}</span>}
        </div>
    );
}
function Empty({ children }) { return <div className="px-4 py-3 text-sm text-gray-400">{children}</div>; }

function TaskRow({ t, selected, onSelect, onToggle }) {
    const carried = t.origin && t.origin < t.week && !t.done;
    return (
        <div onClick={onSelect}
            className={`group flex items-center gap-2 px-3 py-2 border-b border-gray-100 dark:border-gray-800 cursor-pointer ${selected ? 'bg-blue-50 dark:bg-blue-500/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60'}`}>
            <input type="checkbox" checked={t.done} onClick={(e) => e.stopPropagation()} onChange={(e) => onToggle(e.target.checked)}
                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
            <span className={`h-2 w-2 shrink-0 rounded-full ${PRI_DOT[t.pri] || PRI_DOT[0]}`} title={PRI[t.pri]} />
            <div className="flex-1 min-w-0">
                <div className={`text-sm truncate ${t.done ? 'line-through text-gray-400' : 'text-gray-800 dark:text-gray-100'}`}>{t.title}</div>
                <div className="flex items-center gap-2 text-xs text-gray-400">
                    {t.assignee && <span className="truncate">{t.assignee}</span>}
                    {!t.done && t.pct > 0 && <span>· {t.pct}%</span>}
                    {carried && <span className="text-blue-500" title="carried over from an earlier week">↻</span>}
                </div>
            </div>
        </div>
    );
}

function ProjectRow({ p, selected, onSelect }) {
    const st = p.status || (p.pct >= 100 ? 'Done' : p.pct > 0 ? 'In progress' : 'Proposed');
    return (
        <div onClick={onSelect}
            className={`group px-3 py-2.5 border-b border-gray-100 dark:border-gray-800 cursor-pointer ${selected ? 'bg-blue-50 dark:bg-blue-500/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60'}`}>
            <div className="flex items-center gap-2">
                <span className={`h-2 w-2 shrink-0 rounded-full ${PRI_DOT[p.pri] || PRI_DOT[0]}`} title={PRI[p.pri]} />
                <span className="flex-1 min-w-0 truncate text-sm font-medium text-gray-800 dark:text-gray-100">{p.title}</span>
                <span className={`text-[11px] px-1.5 py-0.5 rounded ${STATUS_STYLE[st] || STATUS_STYLE.Proposed}`}>{st}</span>
            </div>
            <div className="mt-1.5 flex items-center gap-2 pl-4">
                <div className="flex-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                    <div className="h-full bg-blue-600" style={{ width: `${p.pct || 0}%` }} />
                </div>
                <span className="text-xs text-gray-400 w-8 text-right">{p.pct || 0}%</span>
            </div>
        </div>
    );
}

function TaskDetail({ t, patch, onDelete, onMakeProject, currentWeek }) {
    const set = (changes, opts) => patch(t.id, changes, opts);
    const carried = t.origin && t.origin < t.week;
    return (
        <div className="max-w-3xl mx-auto">
            <div className="flex items-start justify-between gap-4 mb-1">
                <input value={t.title} onChange={(e) => set({ title: e.target.value }, { debounce: true, reload: false })}
                    className="flex-1 text-2xl font-bold text-gray-900 dark:text-white border-0 focus:ring-0 px-0 bg-transparent placeholder-gray-300" placeholder="Task title" />
                <div className="flex items-center gap-2 mt-2 shrink-0">
                    <button onClick={onMakeProject} className="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-indigo-400 hover:text-indigo-600"><WrenchIcon /> Make project</button>
                    <button onClick={onDelete} title="Delete task" className="px-2 py-1.5 rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600"><TrashIcon /></button>
                </div>
            </div>
            {carried && <div className="mb-4 text-xs text-blue-500">↻ Carried over from {new Date(parseYmd(t.origin)).toLocaleDateString()}</div>}

            <div className="grid grid-cols-2 gap-4 mt-4 mb-4">
                <Field label="Week">
                    <div className="flex items-center gap-2">
                        <input type="date" value={t.week || ''} onChange={(e) => set({ week: e.target.value })}
                            className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                        {t.week !== currentWeek && <button onClick={() => set({ week: currentWeek })} className="shrink-0 text-xs text-blue-600 hover:underline whitespace-nowrap">This week</button>}
                    </div>
                </Field>
                <Field label="Assigned to">
                    <SearchSelect value={t.assigned_to} endpoint="/data/people-options" placeholder="Unassigned"
                        onChange={(id) => set({ assigned_to: id })} />
                </Field>
            </div>

            <div className="grid grid-cols-2 gap-4 mb-4">
                <Field label="Priority">
                    <div className="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
                        {PRI.map((label, i) => (
                            <button key={i} onClick={() => set({ pri: i })}
                                className={`px-3 py-1.5 ${t.pri === i ? 'bg-blue-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'} ${i ? 'border-l border-gray-200 dark:border-gray-700' : ''}`}>
                                {label}
                            </button>
                        ))}
                    </div>
                </Field>
                <Field label={`Progress — ${t.pct}%`}>
                    <input type="range" min="0" max="100" step="5" value={t.pct}
                        onChange={(e) => set({ pct: Number(e.target.value) })} className="w-full accent-blue-600" />
                </Field>
            </div>

            <label className="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" checked={t.done} onChange={(e) => set({ done: e.target.checked })}
                    className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" /> Done
            </label>
        </div>
    );
}

function ProjectDetail({ t, statuses, patch, onDelete, onMoveToTasks }) {
    const set = (changes, opts) => patch(t.id, changes, opts);
    return (
        <div className="max-w-3xl mx-auto">
            <div className="flex items-start justify-between gap-4 mb-4">
                <input value={t.title} onChange={(e) => set({ title: e.target.value }, { debounce: true, reload: false })}
                    className="flex-1 text-2xl font-bold text-gray-900 dark:text-white border-0 focus:ring-0 px-0 bg-transparent placeholder-gray-300" placeholder="Project name" />
                <div className="flex items-center gap-2 mt-2 shrink-0">
                    <button onClick={onMoveToTasks} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600">↩ To tasks</button>
                    <button onClick={onDelete} title="Delete project" className="px-2 py-1.5 rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600"><TrashIcon /></button>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4 mb-4">
                <Field label="Status">
                    <select value={t.status || ''} onChange={(e) => set({ status: e.target.value })}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">—</option>
                        {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                </Field>
                <Field label="Assigned to">
                    <SearchSelect value={t.assigned_to} endpoint="/data/people-options" placeholder="Unassigned"
                        onChange={(id) => set({ assigned_to: id })} />
                </Field>
            </div>

            <div className="grid grid-cols-2 gap-4 mb-6">
                <Field label="Priority">
                    <div className="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
                        {PRI.map((label, i) => (
                            <button key={i} onClick={() => set({ pri: i })}
                                className={`px-3 py-1.5 ${t.pri === i ? 'bg-blue-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'} ${i ? 'border-l border-gray-200 dark:border-gray-700' : ''}`}>
                                {label}
                            </button>
                        ))}
                    </div>
                </Field>
                <Field label={`Progress — ${t.pct}%`}>
                    <input type="range" min="0" max="100" step="5" value={t.pct}
                        onChange={(e) => set({ pct: Number(e.target.value) })} className="w-full accent-blue-600" />
                </Field>
            </div>

            <div className="space-y-4">
                {[
                    ['details', 'Details'], ['impact', 'Impact'], ['needs', 'Needs'],
                    ['challenges', 'Challenges'], ['workarounds', 'Workarounds'],
                ].map(([k, label]) => (
                    <Field key={k} label={label}>
                        <textarea rows={2} value={t[k] || ''} onChange={(e) => set({ [k]: e.target.value }, { debounce: true, reload: false })}
                            className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                    </Field>
                ))}
            </div>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">{label}</span>
            {children}
        </label>
    );
}

function WrenchIcon() {
    return (<svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" /></svg>);
}
