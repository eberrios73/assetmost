import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import { TrashIcon, Chevron } from '@/Components/Icons';
import { buildDocBody, templateCategory } from '@/docTemplates';
import TemplateMenu from '@/Components/TemplateMenu';
import SearchSelect from '@/Components/SearchSelect';
import AddButton from '@/Components/ui/AddButton';
import NotesCanvas from '@/Components/NotesCanvas';
import { openRecordForm } from '@/lib/formBus';

// A task stamped "Form: new|edit <kind> · co:<id>" (a /form token in its workflow
// step) carries the record form — the button summons the app-wide drawer with
// the workflow's company as context; the result is noted on the task.
const taskForm = (notes) => {
    // Notes are HTML now; the Form: token matches against the text they contain.
    const text = (notes || '').replace(/<[^>]+>/g, '\n');
    const m = /(?:^|\n)\s*Form: (?:(new|edit) )?(device|person|account|location) · co:(\d+)/.exec(text);
    return m ? { mode: m[1] || 'new', kind: m[2], companyId: Number(m[3]) } : null;
};

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const api = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then((r) => (r.status === 204 ? {} : r.json()));

const PRI = ['·', 'Low', 'Med', 'High'];
const PRI_PILL = [
    'text-gray-500 bg-gray-100 dark:bg-gray-700 dark:text-gray-300',
    'text-blue-700 bg-blue-100 dark:bg-blue-500/20 dark:text-blue-300',
    'text-amber-700 bg-amber-100 dark:bg-amber-500/20 dark:text-amber-300',
    'text-red-700 bg-red-100 dark:bg-red-500/20 dark:text-red-300',
];
const PRI_BAR = ['', 'before:bg-blue-500', 'before:bg-amber-500', 'before:bg-red-500'];

// The whole workflow: todo → doing → blocked → (done via the checkbox).
// Click the chip to cycle; 'todo' renders nothing so the default stays quiet.
const STATE_STYLE = {
    doing: 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
    blocked: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
};
const NEXT_STATE = { todo: 'doing', doing: 'blocked', blocked: 'todo' };
function StateChip({ t, patch }) {
    if (t.done) return null;
    const s = t.state || 'todo';
    return (
        <button onClick={() => patch(t.id, { state: NEXT_STATE[s] || 'doing' })}
            title="click to cycle: todo → doing → blocked"
            className={`shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${STATE_STYLE[s] || 'text-gray-300 dark:text-gray-600 hover:text-gray-500'}`}>
            {s === 'todo' ? '·' : s}
        </button>
    );
}
function LabelChips({ labels }) {
    if (!labels) return null;
    return labels.split(',').map((l) => l.trim()).filter(Boolean).map((l) => (
        <span key={l} className="shrink-0 rounded bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 text-[10px] text-gray-500 dark:text-gray-300">{l}</span>
    ));
}

/**
 * Age = how long a task has been open, counted from `origin` (the first week it
 * appeared — carry-overs keep aging, that's the point). Done tasks freeze at
 * their completion date. Color steps up as it gets old: quiet < 1w, amber < 2w,
 * red after — the sheet should make neglect visible without anyone asking.
 */
function Age({ t }) {
    const start = t.origin || t.week;
    if (!start) return <span className="text-gray-300">—</span>;
    const end = t.done && t.completed_at ? new Date(t.completed_at) : new Date();
    const days = Math.max(0, Math.round((end - new Date(start)) / 86400000));
    const label = days < 14 ? `${days}d` : days < 60 ? `${Math.round(days / 7)}w` : `${Math.round(days / 30)}mo`;
    const tone = t.done ? 'text-gray-300 dark:text-gray-600'
        : days >= 14 ? 'text-red-600 font-semibold'
        : days >= 7 ? 'text-amber-600 font-medium'
        : 'text-gray-400';
    return <span className={tone} title={`Started ${start}`}>{label}</span>;
}
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
function weekLabel(wk, currentWeek) {
    const d = parseYmd(wk);
    const base = `${fmt(d)} – ${fmt(addDays(d, 6))} · W${isoWeek(d)}`;
    return wk === currentWeek ? `This week · ${base}` : base;
}

export default function Index() {
    const [tasks, setTasks] = useState([]);
    const [statuses, setStatuses] = useState([]);
    const [people, setPeople] = useState([]);
    const [currentWeek, setCurrentWeek] = useState(ymd(mondayOf(new Date())));
    const [mode, setMode] = useState('tasks');       // 'tasks' | 'projects'
    const [view, setView] = useState(ymd(mondayOf(new Date())));
    const [openProj, setOpenProj] = useState(null);
    const [expandedId, setExpandedId] = useState(null);
    const [status, setStatus] = useState('');
    const timers = useRef({});

    const load = () => api('/data/tasks').then((r) => { setTasks(r.tasks); setStatuses(r.statuses); setCurrentWeek(r.currentWeek); });
    useEffect(() => { load(); api('/data/people-options').then(setPeople); }, []);

    const flash = (msg = 'Saved') => { setStatus(msg); clearTimeout(timers.current._s); timers.current._s = setTimeout(() => setStatus(''), 1200); };

    // optimistic patch; reload re-groups rows (done/rollover/project moves)
    const patch = (id, changes, { debounce, reload = false } = {}) => {
        setTasks((ts) => ts.map((t) => (t.id === id ? { ...t, ...changes } : t)));
        const send = () => { setStatus('Saving…'); api(`/data/tasks/${id}`, 'PATCH', changes).then(() => { flash(); if (reload) load(); }); };
        if (debounce) { clearTimeout(timers.current[id]); timers.current[id] = setTimeout(send, 500); }
        else send();
    };
    const addTask = async (title, isProject = false) => {
        const body = { title, week: isProject ? currentWeek : view, ...(isProject ? { kind: 'project', status: 'Proposed' } : {}) };
        const { id } = await api('/data/tasks', 'POST', body);
        await load();
        if (isProject) setOpenProj(id);
    };
    const remove = async (id, title) => { if (confirm(`Delete "${title}"?`)) { await api(`/data/tasks/${id}`, 'DELETE'); load(); } };
    const setProject = (id, on) => patch(id, { kind: on ? 'project' : 'task' }, { reload: true });
    const addMilestone = async (projectId, title) => {
        await api('/data/tasks', 'POST', { title, kind: 'milestone', parent_id: projectId, week: currentWeek });
        load();
    };

    // Turn a task/project into a doc: create a page from a template (seeded with
    // its notes/details) and open it in the Docs wiki.
    const makeDoc = async (rec, templateKey, custom = null) => {
        const background = rec.notes || rec.details || '';
        const { id } = await api('/data/docs', 'POST', custom ? {
            title: rec.title, category: custom.category || null,
            body: (background ? `<h2>Background</h2><p>${background}</p>` : '') + (custom.body || '<p></p>'),
        } : {
            title: rec.title, body: buildDocBody(templateKey, background), category: templateCategory(templateKey),
        });
        router.visit(`/docs?page=${id}`);
    };

    // ---- weekly grid data ----
    // kind partitions the sheet: projects (and subprojects), milestones, tasks.
    const nonProjects = useMemo(() => tasks.filter((t) => t.kind === 'task'), [tasks]);
    const projects = useMemo(() => tasks.filter((t) => t.kind === 'project').sort((a, b) => a.ord - b.ord), [tasks]);
    const milestones = useMemo(() => tasks.filter((t) => t.kind === 'milestone'), [tasks]);

    // Subtasks: parent_id pointing at another TASK. A parent that's a project or
    // milestone keeps the row top-level in the week grid — grouping into projects
    // and milestones is the other views' job.
    const projectIds = useMemo(() => new Set(projects.map((pj) => pj.id)), [projects]);
    const milestoneIds = useMemo(() => new Set(milestones.map((m) => m.id)), [milestones]);
    const isSub = (t) => t.parent_id && !projectIds.has(t.parent_id) && !milestoneIds.has(t.parent_id);
    const subsByParent = useMemo(() => {
        const m = {};
        for (const t of nonProjects) if (isSub(t)) (m[t.parent_id] ??= []).push(t);
        return m;
    }, [nonProjects, projectIds, milestoneIds]);
    const topLevel = useMemo(() => nonProjects.filter((t) => !isSub(t)), [nonProjects, projectIds, milestoneIds]);
    const addSub = async (parentId, title, week) => { await api('/data/tasks', 'POST', { title, week, parent_id: parentId }); load(); };

    const weekTasks = useMemo(() => topLevel.filter((t) => t.week === view), [topLevel, view]);
    const open = useMemo(() => weekTasks.filter((t) => !t.done).sort((a, b) => (b.pri - a.pri) || (a.ord - b.ord)), [weekTasks]);
    const completedGroups = useMemo(() => {
        const out = [];
        for (let i = 0; i < 4; i++) {
            const wk = ymd(addDays(parseYmd(view), -7 * i));
            const items = topLevel.filter((t) => t.week === wk && t.done).sort((a, b) => a.ord - b.ord);
            if (items.length) out.push({ wk, items });
        }
        return out;
    }, [topLevel, view]);
    const avg = weekTasks.length ? Math.round(weekTasks.reduce((s, t) => s + (t.pct || 0), 0) / weekTasks.length) : 0;

    const viewDate = parseYmd(view);
    const doneThisWeek = weekTasks.filter((t) => t.done).length;

    // Viewing a FUTURE week: unfinished tasks only roll forward when a week becomes
    // current (Monday), so preview what will land — otherwise next week looks
    // deceptively empty while this week is still in progress.
    const viewIsFuture = view > currentWeek;
    const rollingIn = useMemo(
        () => (viewIsFuture ? topLevel.filter((t) => !t.done && t.week < view).sort((a, b) => (b.pri - a.pri) || (a.ord - b.ord)) : []),
        [viewIsFuture, topLevel, view]
    );

    const content = (
        <div className="max-w-6xl mx-auto">
            <div className="flex items-center justify-between border-b border-gray-200 dark:border-gray-800 mb-5">
                <div className="flex">
                    {['tasks', 'projects', 'timeline'].map((m) => (
                        <button key={m} onClick={() => setMode(m)}
                            className={`px-4 py-2.5 text-sm font-medium border-b-2 -mb-px capitalize ${mode === m ? 'text-blue-600 border-blue-600' : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'}`}>
                            {m}{m === 'projects' && projects.length ? ` (${projects.length})` : ''}
                        </button>
                    ))}
                </div>
                <span className="text-xs text-gray-400">{status}</span>
            </div>

            {mode === 'tasks' ? (
                <>
                    <div className="flex items-center gap-3 mb-3">
                        <div className="flex items-center gap-1.5">
                            <NavBtn onClick={() => setView(ymd(addDays(viewDate, -7)))}>‹ Prev</NavBtn>
                            <NavBtn onClick={() => setView(currentWeek)} active={view === currentWeek}>This week</NavBtn>
                            <NavBtn onClick={() => setView(ymd(addDays(viewDate, 7)))}>Next ›</NavBtn>
                        </div>
                        <div className="flex items-baseline gap-3">
                            <span className="text-lg font-semibold text-gray-800 dark:text-gray-100">{fmt(viewDate)} – {fmt(addDays(viewDate, 6))}</span>
                            <span className="text-xs uppercase tracking-wide text-gray-400">Week {isoWeek(viewDate)} · {viewDate.getFullYear()}</span>
                        </div>
                    </div>

                    <AddBar placeholder="Add a task and press Enter…" onAdd={(v) => addTask(v)} />

                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-sm border border-gray-200 dark:border-gray-800 rounded-lg overflow-hidden">
                        <thead>
                            <tr className="bg-gray-100 dark:bg-gray-800/70 text-xs uppercase tracking-wide text-gray-400">
                                <Th className="w-9" /><Th>Task</Th><Th className="w-44">Assignee</Th>
                                <Th className="w-16 text-center">Pri</Th><Th className="w-16 text-center">Age</Th><Th className="w-40">%</Th>
                                <Th className="w-32">Completed</Th><Th className="w-8" /><Th className="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            <SectionRow label="Current" right={weekTasks.length ? `${avg}% complete` : ''} />
                            {open.length === 0 && !rollingIn.length && <EmptyRow>{weekTasks.length ? 'All clear for this week.' : 'No tasks yet — add one above.'}</EmptyRow>}
                            {open.map((t) => (
                                <TaskRows key={t.id} t={t} people={people} patch={patch} statuses={statuses} projects={projects} milestones={milestones} allTasks={nonProjects}
                                    expandedId={expandedId} onToggleAny={(id) => setExpandedId(expandedId === id ? null : id)}
                                    subs={subsByParent} onAddSub={addSub}
                                    onProject={setProject} onMakeDoc={makeDoc} onDelete={remove} />
                            ))}
                            {rollingIn.length > 0 && (
                                <FragmentRows>
                                    <SubRow label={`Will roll in Monday — still open on earlier weeks`} right={`${rollingIn.length} task${rollingIn.length === 1 ? '' : 's'}`} />
                                    {rollingIn.map((t) => (
                                        <tr key={`preview-${t.id}`} className="border-b border-gray-100 dark:border-gray-800 opacity-50">
                                            <td className={`px-3 py-1.5 relative before:absolute before:left-0 before:top-0 before:h-full before:w-[3px] ${PRI_BAR[t.pri] || ''}`} />
                                            <td className="px-3 py-1.5 text-gray-600 dark:text-gray-300">{t.title} <span className="text-blue-500">↻</span></td>
                                            <td className="px-3 py-1.5 text-gray-400">{people.find((p) => p.id === t.assigned_to)?.label || 'Unassigned'}</td>
                                            <td className="px-3 py-1.5 text-center">
                                                <span className={`inline-block min-w-[42px] px-2 py-0.5 rounded-full text-[11px] font-semibold ${PRI_PILL[t.pri] || PRI_PILL[0]}`}>{PRI[t.pri]}</span>
                                            </td>
                                            <td className="px-3 py-1.5 text-center text-xs"><Age t={t} /></td>
                                            <td className="px-3 py-1.5 text-xs text-gray-400">{t.pct || 0}%</td>
                                            <td colSpan={3} />
                                        </tr>
                                    ))}
                                </FragmentRows>
                            )}
                            {completedGroups.length > 0 && <SectionRow label="Completed" />}
                            {completedGroups.map((g) => (
                                <FragmentRows key={g.wk}>
                                    <SubRow label={weekLabel(g.wk, currentWeek)} right={`${g.items.length} done`} />
                                    {g.items.map((t) => (
                                        <TaskRows key={t.id} t={t} people={people} patch={patch} statuses={statuses} projects={projects} milestones={milestones} allTasks={nonProjects}
                                            expandedId={expandedId} onToggleAny={(id) => setExpandedId(expandedId === id ? null : id)}
                                            subs={subsByParent} onAddSub={addSub}
                                            onProject={setProject} onMakeDoc={makeDoc} onDelete={remove} />
                                    ))}
                                </FragmentRows>
                            ))}
                        </tbody>
                    </table>
                    </div>
                    <p className="mt-3 text-xs text-gray-400">↻ carried over from an earlier week — unfinished tasks roll into the current week automatically.</p>
                </>
            ) : mode === 'timeline' ? (
                <Gantt projects={projects} tasks={nonProjects} currentWeek={currentWeek} patch={patch} onOpenProject={(id) => { setMode('projects'); setOpenProj(id); }} />
            ) : (
                <>
                    <AddBar placeholder="Add a project and press Enter…" onAdd={(v) => addTask(v, true)} />
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm border border-gray-200 dark:border-gray-800 rounded-lg overflow-hidden">
                        <thead>
                            <tr className="bg-gray-100 dark:bg-gray-800/70 text-xs uppercase tracking-wide text-gray-400">
                                <Th>Project</Th><Th className="w-40">Status</Th><Th className="w-48">Progress</Th><Th className="w-10 text-center" />
                            </tr>
                        </thead>
                        <tbody>
                            {projects.length === 0 && <tr><td colSpan={4} className="px-3 py-4 text-gray-400">No projects yet — add one, or flag a task with the wrench.</td></tr>}
                            {projects.filter((p) => !projectIds.has(p.parent_id))
                                .flatMap((p) => [{ p, depth: 0 }, ...projects.filter((c) => c.parent_id === p.id).map((c) => ({ p: c, depth: 1 }))])
                                .map(({ p, depth }) => (
                                <FragmentRows key={p.id}>
                                    <ProjectRow p={p} depth={depth} open={openProj === p.id} patch={patch} onToggle={() => setOpenProj(openProj === p.id ? null : p.id)} />
                                    {openProj === p.id && (
                                        <ProjectDetailRow p={p} statuses={statuses} people={people} patch={patch} onMakeDoc={makeDoc}
                                            milestones={milestones.filter((m) => m.parent_id === p.id)}
                                            taskCountFor={(mid) => nonProjects.filter((t) => t.parent_id === mid).length}
                                            parents={projects.filter((o) => o.id !== p.id && !projectIds.has(o.parent_id))}
                                            onAddMilestone={(title) => addMilestone(p.id, title)}
                                            onRemove={remove}
                                            onMoveToTasks={() => { setProject(p.id, false); setOpenProj(null); }}
                                            onDelete={() => { remove(p.id, p.title); setOpenProj(null); }} />
                                    )}
                                </FragmentRows>
                            ))}
                        </tbody>
                    </table>
                    </div>
                </>
            )}
        </div>
    );

    const footer = (
        <div className="flex w-full justify-between">
            <span>{mode === 'tasks' ? `${weekLabel(view, currentWeek)} — ${doneThisWeek} of ${weekTasks.length} done` : `Projects — ${projects.length}`}</span>
            <span className="text-gray-400">{status}</span>
        </div>
    );

    return (
        <>
            <Head title="Tasks" />
            <AppShell active="tasks" detail={content} footer={footer} />
        </>
    );
}

function FragmentRows({ children }) { return children; }

function Th({ children, className = '' }) { return <th className={`text-left font-normal px-3 py-2 ${className}`}>{children}</th>; }

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
        <input value={v} onChange={(e) => setV(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && v.trim()) { onAdd(v.trim()); setV(''); } }}
            placeholder={placeholder}
            className="w-full mb-3 rounded-lg border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500 placeholder-gray-400" />
    );
}

function SectionRow({ label, right }) {
    return (
        <tr className="bg-gray-100 dark:bg-gray-800/70 border-y border-gray-200 dark:border-gray-800">
            <td colSpan={9} className="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {label}{right && <span className="float-right text-blue-600 dark:text-blue-400 normal-case tracking-normal">{right}</span>}
            </td>
        </tr>
    );
}
function SubRow({ label, right }) {
    return (
        <tr className="bg-gray-50 dark:bg-gray-900/60">
            <td colSpan={9} className="px-3 py-1 pl-6 text-xs text-gray-400 border-b border-gray-100 dark:border-gray-800">
                <span className="uppercase tracking-wide">{label}</span>{right && <span className="float-right">{right}</span>}
            </td>
        </tr>
    );
}
function EmptyRow({ children }) { return <tr><td colSpan={9} className="px-3 py-3 text-gray-400">{children}</td></tr>; }

function TaskRows({ t, people, patch, projects = [], milestones = [], allTasks = [], subs = {}, onAddSub, depth = 0, expandedId, onToggleAny, onProject, onMakeDoc, onDelete }) {
    const expanded = expandedId === t.id;
    const onToggle = () => onToggleAny(t.id);
    const carried = t.origin && t.origin < t.week && !t.done;
    const form = taskForm(t.notes);
    const openForm = () => openRecordForm({
        mode: form.mode, kind: form.kind, companyId: form.companyId,
        onSaved: (rec) => {
            const label = rec?.asset_tag || rec?.identifier || rec?.name || '';
            const receipt = `✓ ${form.kind} ${form.mode === 'edit' ? 'updated' : 'recorded'}${label ? `: ${label}` : ''}`;
            patch(t.id, { notes: /^\s*</.test(t.notes || '') ? `${t.notes}<p>${receipt}</p>` : `${t.notes}\n${receipt}` });
        },
    });
    return (
        <>
            {/* Priority bar lives on the first cell, NOT the <tr>: a pseudo-element
                on a row gets wrapped in an anonymous table cell, which adds a phantom
                column and makes the header/colSpan rows stop short of the data rows. */}
            <tr className="group border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                <td className={`px-3 py-1.5 text-center relative before:absolute before:left-0 before:top-0 before:h-full before:w-[3px] ${PRI_BAR[t.pri] || ''}`}>
                    <input type="checkbox" checked={t.done} onChange={(e) => patch(t.id, { done: e.target.checked }, { reload: true })}
                        className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                </td>
                <td className="px-3 py-1.5">
                    <div className="flex items-center gap-2" style={depth ? { marginLeft: depth * 20 } : undefined}>
                        {depth > 0 && <span className="text-gray-300 dark:text-gray-600">↳</span>}
                        <InlineText value={t.title} done={t.done} onCommit={(v) => patch(t.id, { title: v }, { debounce: true })} />
                        <StateChip t={t} patch={patch} />
                        <LabelChips labels={t.labels} />
                        {t.notes ? <span className="text-gray-300 dark:text-gray-600 shrink-0" title="has notes"><NoteGlyph /></span> : null}
                        {carried && <span className="text-blue-500 shrink-0" title="carried over from an earlier week">↻</span>}
                    </div>
                </td>
                <td className="px-3 py-1.5"><AssigneeSelect value={t.assigned_to} people={people} onChange={(id) => patch(t.id, { assigned_to: id })} /></td>
                <td className="px-3 py-1.5 text-center">
                    <select value={t.pri || 0} onChange={(e) => patch(t.id, { pri: Number(e.target.value) })} title="priority"
                        className={`min-w-[58px] cursor-pointer rounded-full border-0 py-0.5 pl-2.5 pr-6 text-[11px] font-semibold focus:ring-1 focus:ring-blue-500 ${PRI_PILL[t.pri] || PRI_PILL[0]}`}>
                        {PRI.map((label, i) => <option key={i} value={i}>{i === 0 ? 'None' : label}</option>)}
                    </select>
                </td>
                <td className="px-3 py-1.5 text-center text-xs"><Age t={t} /></td>
                <td className="px-3 py-1.5"><PctCell t={t} onCommit={(v) => patch(t.id, { pct: v }, { reload: true })} /></td>
                <td className="px-3 py-1.5">
                    {t.done && (
                        <input type="date" value={t.completed_at || ''} onChange={(e) => patch(t.id, { completed_at: e.target.value || null })}
                            className="border-0 bg-transparent p-0 text-xs text-gray-500 dark:text-gray-400 focus:ring-0" />
                    )}
                </td>
                <td className="px-1 py-1.5 text-center">
                    <button onClick={onToggle} title="details & notes"
                        className={`px-1 ${expanded ? 'text-blue-600' : 'text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-200'}`}><Chevron open={expanded} className="h-3.5 w-3.5" /></button>
                </td>
                <td className="px-3 py-1.5 text-center">
                    <button onClick={() => onDelete(t.id, t.title)} title="delete"
                        className="text-gray-300 dark:text-gray-600 opacity-0 group-hover:opacity-100 hover:text-red-600"><TrashIcon /></button>
                </td>
            </tr>
            {expanded && (
                <tr>
                    <td colSpan={9} className="p-0 border-b border-gray-200 dark:border-gray-800">
                        <div className="bg-gray-50 dark:bg-gray-900/50 p-4">
                            {form && (
                                <div className="mb-3 flex items-center gap-2">
                                    <AddButton label={`${form.mode === 'edit' ? 'Edit' : 'Add'} ${form.kind}`} onClick={openForm} />
                                    <span className="text-xs text-gray-400">This step {form.mode === 'edit' ? 'updates' : 'records'} a {form.kind} — in the workflow's company.</span>
                                </div>
                            )}
                            <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Notes</span>
                            <NotesCanvas value={t.notes} onCommit={(v) => patch(t.id, { notes: v === '<p></p>' ? null : v })} />
                            <div className="mt-3">
                                <CommentsLog taskId={t.id} />
                            </div>
                            <div className="mt-3 flex flex-wrap items-end gap-3">
                                <label className="block w-52">
                                    <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Project / milestone</span>
                                    <select value={t.parent_id ?? ''} onChange={(e) => patch(t.id, { parent_id: e.target.value ? Number(e.target.value) : null })}
                                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">— none —</option>
                                        {projects.map((p) => (
                                            <optgroup key={p.id} label={p.title}>
                                                <option value={p.id}>{p.title}</option>
                                                {milestones.filter((m) => m.parent_id === p.id)
                                                    .map((m) => <option key={m.id} value={m.id}>◆ {m.title}</option>)}
                                            </optgroup>
                                        ))}
                                    </select>
                                </label>
                                <label className="block w-44">
                                    <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Labels</span>
                                    <input defaultValue={t.labels || ''} placeholder="comma, separated"
                                        onBlur={(e) => { if (e.target.value !== (t.labels || '')) patch(t.id, { labels: e.target.value || null }); }}
                                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
                                </label>
                                <label className="block w-24">
                                    <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Priority</span>
                                    <select value={t.pri || 0} onChange={(e) => patch(t.id, { pri: Number(e.target.value) })}
                                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500">
                                        {PRI.map((label, i) => <option key={i} value={i}>{i === 0 ? 'None' : label}</option>)}
                                    </select>
                                </label>
                                <label className="block w-36">
                                    <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Planned</span>
                                    <input type="date" value={t.planned_start || ''} onChange={(e) => patch(t.id, { planned_start: e.target.value || null })}
                                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
                                </label>
                                <label className="block w-36">
                                    <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Due</span>
                                    <input type="date" value={t.due_date || ''} onChange={(e) => patch(t.id, { due_date: e.target.value || null })}
                                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
                                </label>
                                <label className="block w-44">
                                    <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">After</span>
                                    <select value={t.depends_on_id ?? ''} onChange={(e) => patch(t.id, { depends_on_id: e.target.value ? Number(e.target.value) : null })}
                                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">— none —</option>
                                        {allTasks.filter((o) => o.id !== t.id).map((o) => <option key={o.id} value={o.id}>{o.title}</option>)}
                                    </select>
                                </label>
                                <div className="ml-auto flex items-end gap-2">
                                    <button onClick={() => onProject(t.id, true)}
                                        className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-indigo-400 hover:text-indigo-600"><Wrench /> Make project</button>
                                    <input placeholder="Add subtask ⏎"
                                        onKeyDown={(e) => { if (e.key === 'Enter' && e.target.value.trim()) { onAddSub?.(t.id, e.target.value.trim(), t.week); e.target.value = ''; } }}
                                        className="w-40 rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-2 focus:border-blue-500 focus:ring-blue-500" />
                                    <TemplateMenu label="Make doc" glyph={<DocGlyph />} onPick={(k, c) => onMakeDoc(t, k, c)}
                                        className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600" />
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            )}
            {(subs[t.id] || []).map((st) => (
                <TaskRows key={st.id} t={st} people={people} patch={patch} projects={projects} milestones={milestones} allTasks={allTasks}
                    subs={subs} onAddSub={onAddSub} depth={depth + 1} expandedId={expandedId} onToggleAny={onToggleAny}
                    onProject={onProject} onMakeDoc={onMakeDoc} onDelete={onDelete} />
            ))}
        </>
    );
}

/**
 * The task log: flat, stamped, append-only. Not a thread — you append facts
 * ("waiting on vendor"), each stamped with who and when. The audit trail of
 * why a task is still open; Notes stays the freeform scratchpad.
 */
function CommentsLog({ taskId }) {
    const [items, setItems] = useState(null);
    useEffect(() => { api(`/data/tasks/${taskId}/comments`).then((d) => setItems(Array.isArray(d) ? d : [])); }, [taskId]);

    const add = async (body) => {
        const c = await api(`/data/tasks/${taskId}/comments`, 'POST', { body });
        if (c?.id) setItems((xs) => [...xs, c]);
    };
    const drop = async (id) => {
        await api(`/data/tasks/${taskId}/comments/${id}`, 'DELETE');
        setItems((xs) => xs.filter((x) => x.id !== id));
    };
    const when = (at) => {
        const d = new Date(at.replace(' ', 'T'));
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    };

    return (
        <div>
            <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Updates</span>
            {items === null ? <div className="text-xs text-gray-400 py-1">…</div> : items.map((c) => (
                <div key={c.id} className="group/log flex items-baseline gap-2 py-0.5 text-sm">
                    <span className="text-gray-700 dark:text-gray-200">{c.body}</span>
                    <span className="shrink-0 text-xs text-gray-400">— {c.author}, {when(c.at)}</span>
                    {c.mine && <button onClick={() => drop(c.id)} title="delete"
                        className="text-gray-300 dark:text-gray-600 opacity-0 group-hover/log:opacity-100 hover:text-red-600">×</button>}
                </div>
            ))}
            <input placeholder="What happened? ⏎ — e.g. waiting on vendor"
                onKeyDown={(e) => { if (e.key === 'Enter' && e.target.value.trim()) { add(e.target.value.trim()); e.target.value = ''; } }}
                className="mt-1 w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
        </div>
    );
}

function ProjectRow({ p, depth = 0, open, patch, onToggle }) {
    const st = p.status || (p.pct >= 100 ? 'Done' : p.pct > 0 ? 'In progress' : 'Proposed');
    return (
        <tr onClick={onToggle} className="cursor-pointer border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
            <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center gap-2" style={depth ? { marginLeft: depth * 20 } : undefined}>
                    {depth > 0 && <span className="text-gray-300 dark:text-gray-600" title="subproject">↳</span>}
                    <InlineText value={p.title} bold onCommit={(v) => patch(p.id, { title: v }, { debounce: true })} />
                </div>
            </td>
            <td className="px-3 py-2"><span className={`text-[11px] px-1.5 py-0.5 rounded ${STATUS_STYLE[st] || STATUS_STYLE.Proposed}`}>{st}</span></td>
            <td className="px-3 py-2">
                <div className="flex items-center gap-2">
                    <div className="flex-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                        <div className="h-full bg-blue-600" style={{ width: `${p.pct || 0}%` }} />
                    </div>
                    <span className="text-xs text-gray-400 w-8 text-right">{p.pct || 0}%</span>
                </div>
            </td>
            <td className="px-3 py-2 text-gray-400"><span className="flex justify-center"><Chevron open={open} className="h-3.5 w-3.5" /></span></td>
        </tr>
    );
}

function ProjectDetailRow({ p, statuses, people, patch, onMakeDoc, onMoveToTasks, onDelete,
    milestones = [], taskCountFor = () => 0, parents = [], onAddMilestone, onRemove }) {
    const set = (changes, opts) => patch(p.id, changes, opts);
    return (
        <tr>
            <td colSpan={4} className="p-0 border-b border-gray-200 dark:border-gray-800">
                <div className="bg-gray-50 dark:bg-gray-900/50 p-4">
                    <div className="mb-4">
                        <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">Milestones</span>
                        {milestones.map((m) => (
                            <div key={m.id} className="group/ms flex items-center gap-3 py-1 border-b border-gray-100 dark:border-gray-800">
                                <span className="text-gray-300 dark:text-gray-600">◆</span>
                                <InlineText value={m.title} done={m.done} onCommit={(v) => patch(m.id, { title: v }, { debounce: true })} />
                                <span className="text-xs text-gray-400">{taskCountFor(m.id)} task{taskCountFor(m.id) === 1 ? '' : 's'}</span>
                                <div className="ml-auto flex items-center gap-3">
                                    <input type="date" value={m.due_date || ''} onChange={(e) => patch(m.id, { due_date: e.target.value || null })}
                                        title="due" className="border-0 bg-transparent p-0 text-xs text-gray-500 dark:text-gray-400 focus:ring-0" />
                                    <PctCell t={m} onCommit={(v) => patch(m.id, { pct: v })} />
                                    <button onClick={() => onRemove(m.id, m.title)} title="delete milestone"
                                        className="text-gray-300 dark:text-gray-600 opacity-0 group-hover/ms:opacity-100 hover:text-red-600">×</button>
                                </div>
                            </div>
                        ))}
                        <input placeholder="Add milestone ⏎"
                            onKeyDown={(e) => { if (e.key === 'Enter' && e.target.value.trim()) { onAddMilestone?.(e.target.value.trim()); e.target.value = ''; } }}
                            className="mt-1.5 w-56 rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
                    </div>
                    <div className="flex flex-wrap items-center gap-5 mb-4">
                        <label className="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">Part of
                            <select value={p.parent_id ?? ''} onChange={(e) => set({ parent_id: e.target.value ? Number(e.target.value) : null }, { reload: true })}
                                title="a project under a project is a subproject"
                                className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm py-1 focus:border-blue-500 focus:ring-blue-500">
                                <option value="">— top level —</option>
                                {parents.map((o) => <option key={o.id} value={o.id}>{o.title}</option>)}
                            </select>
                        </label>
                    </div>
                    <div className="flex flex-wrap items-center gap-5 mb-4">
                        <label className="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">Status
                            <select value={p.status || ''} onChange={(e) => set({ status: e.target.value })}
                                className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm py-1 focus:border-blue-500 focus:ring-blue-500">
                                <option value="">—</option>
                                {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </label>
                        <label className="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">Assignee
                            <AssigneeSelect value={p.assigned_to} people={people} onChange={(id) => set({ assigned_to: id })} />
                        </label>
                        <div className="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">Progress
                            <PctCell t={p} onCommit={(v) => set({ pct: v })} />
                        </div>
                        <div className="ml-auto flex items-center gap-2">
                            <TemplateMenu label="Make doc" glyph={<DocGlyph />} compact onPick={(k, c) => onMakeDoc(p, k, c)} />
                            <button onClick={onMoveToTasks} className="px-2.5 py-1 text-xs rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600">↩ To tasks</button>
                            <button onClick={onDelete} className="px-2.5 py-1 text-xs rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600">× Delete</button>
                        </div>
                    </div>
                    <ProjField label="Details" value={p.details} onCommit={(v) => set({ details: v }, { debounce: true })} />
                    <div className="grid grid-cols-2 gap-4 mt-4">
                        {[['impact', 'Impact'], ['needs', 'Needs'], ['challenges', 'Challenges'], ['workarounds', 'Workarounds']].map(([k, label]) => (
                            <ProjField key={k} label={label} value={p[k]} onCommit={(v) => set({ [k]: v }, { debounce: true })} />
                        ))}
                    </div>
                </div>
            </td>
        </tr>
    );
}

function ProjField({ label, value, onCommit }) {
    const [v, setV] = useState(value || '');
    useEffect(() => { setV(value || ''); }, [value]);
    return (
        <label className="block">
            <span className="block text-xs font-medium uppercase tracking-wide text-gray-400 mb-1">{label}</span>
            <textarea rows={2} value={v} onChange={(e) => { setV(e.target.value); onCommit(e.target.value); }}
                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
        </label>
    );
}

function InlineText({ value, onCommit, done, bold }) {
    const [v, setV] = useState(value);
    useEffect(() => { setV(value); }, [value]);
    return (
        <input value={v}
            onChange={(e) => { setV(e.target.value); onCommit(e.target.value); }}
            className={`w-full bg-transparent border-0 p-0 focus:ring-0 text-sm ${bold ? 'font-medium' : ''} ${done ? 'line-through text-gray-400' : 'text-gray-800 dark:text-gray-100'}`} />
    );
}

function AssigneeSelect({ value, people, onChange }) {
    // Searchable typeahead (same component as the rest of the app). `people` is
    // already loaded once by the page, so pass it as options instead of refetching
    // per row; `portal` keeps the menu from being clipped by the table's overflow.
    return (
        <SearchSelect
            value={value ?? null}
            options={people}
            onChange={(id) => onChange(id ? Number(id) : null)}
            placeholder="Unassigned"
            portal
            className="w-40 max-w-full"
            inputClassName="w-full truncate border-0 bg-transparent p-0 pr-5 text-sm text-gray-700 dark:text-gray-200 placeholder:text-gray-400 hover:text-gray-900 dark:hover:text-white focus:ring-0 cursor-pointer"
        />
    );
}

function PctCell({ t, onCommit }) {
    const [v, setV] = useState(t.pct);
    useEffect(() => { setV(t.pct); }, [t.pct]);
    const commit = () => { if (v !== t.pct) onCommit(v); };
    return (
        <div className="flex items-center gap-2">
            <input type="range" min="0" max="100" step="5" value={v}
                onChange={(e) => setV(Number(e.target.value))} onPointerUp={commit} onBlur={commit} onKeyUp={commit}
                className="w-24 accent-blue-600" />
            <span className={`text-xs w-9 text-right ${v >= 100 ? 'text-blue-600 font-semibold' : 'text-gray-400'}`}>{v}%</span>
        </div>
    );
}

function NotesArea({ value, onCommit }) {
    const [v, setV] = useState(value || '');
    useEffect(() => { setV(value || ''); }, [value]);
    return (
        <textarea rows={3} value={v} onChange={(e) => { setV(e.target.value); onCommit(e.target.value); }}
            placeholder="Working notes — what you did, gotchas, commands… (the raw material for a doc)"
            className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
    );
}

function Wrench() {
    return (<svg className="h-4 w-4 inline-block align-middle" viewBox="0 0 24 24" fill="currentColor"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" /></svg>);
}
function DocGlyph() {
    return (<svg className="h-4 w-4 inline-block align-middle" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6"><path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" /><path d="M14 3v4h4M8 13h8M8 17h6" /></svg>);
}
function NoteGlyph() {
    return (<svg className="h-3.5 w-3.5 inline-block align-middle" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path d="M4 6h16M4 12h16M4 18h10" /></svg>);
}

/* ---------------- Timeline (Gantt) ---------------- */

const GANTT_STATUS_BAR = {
    Done: 'bg-gray-300 dark:bg-gray-600',
    Blocked: 'bg-red-500',
    'On hold': 'bg-amber-500',
    'In progress': 'bg-teal-500',
    Approved: 'bg-green-500',
    Proposed: 'bg-indigo-400',
};
const GANTT_PRI_BAR = ['bg-blue-400', 'bg-blue-500', 'bg-amber-500', 'bg-red-500'];
const ROW_H = { project: 34, task: 26, sect: 26 };

/**
 * Timeline. Bars show the PLANNED window (planned_start → due/completion);
 * `origin` — when the task was born — is the fixed ◆ tick and never moves.
 * Interactive: drag a bar horizontally to replan it (creation date stays put),
 * drag it vertically onto a project to refile, drag the ○ handle to chain,
 * click an arrowhead to unlink. Overdue open bars get a red ring.
 */
function Gantt({ projects, tasks, currentWeek, patch, onOpenProject }) {
    const canvasRef = useRef(null);
    const listRef = useRef([]);
    const [drag, setDrag] = useState(null);

    const today = new Date();
    const start = (t) => parseYmd(t.planned_start || t.origin || t.week);
    const end = (t) => (t.done && t.completed_at ? parseYmd(t.completed_at) : (t.due_date ? parseYmd(t.due_date) : today));
    const overdue = (t) => !t.done && t.due_date && parseYmd(t.due_date) < today;

    const projectIds = new Set(projects.map((p) => p.id));
    const subsOf = (id) => tasks.filter((t) => t.parent_id === id);

    const list = [];
    const pushTask = (t, level) => {
        list.push({ kind: 'task', item: t, level });
        subsOf(t.id).forEach((st) => pushTask(st, level + 1));
    };
    for (const p of projects) {
        const children = subsOf(p.id);
        const flat = [];
        const collect = (t) => { flat.push(t); subsOf(t.id).forEach(collect); };
        children.forEach(collect);
        const starts = [start(p), ...flat.map(start)];
        const ends = [end(p), ...flat.map(end)];
        list.push({ kind: 'project', item: p, s: new Date(Math.min(...starts)), e: new Date(Math.max(...ends)) });
        children.forEach((t) => pushTask(t, 0));
    }
    const orphans = tasks.filter((t) => !t.parent_id && !t.done);
    if (orphans.length) {
        list.push({ kind: 'sect', label: 'No project — open tasks' });
        orphans.forEach((t) => pushTask(t, 0));
    }

    let y = 0;
    const yMid = {};
    for (const r of list) { r.y = y; if (r.item) yMid[r.item.id] = y + ROW_H[r.kind] / 2; y += ROW_H[r.kind]; }
    const bodyH = y;
    listRef.current = list;

    const items = list.filter((r) => r.item);
    const lo = mondayOf(new Date(Math.min(...items.map((r) => r.s || start(r.item)), today)));
    const hi = addDays(mondayOf(new Date(Math.max(...items.map((r) => r.e || end(r.item)), today))), 13);
    const total = (hi - lo) / 86400000;
    const X = (d) => ((d - lo) / 86400000 / total) * 100;
    const weeks = [];
    for (let d = new Date(lo); d < hi; d = addDays(d, 7)) weeks.push(new Date(d));

    const byId = (id) => tasks.find((o) => o.id === id) || projects.find((o) => o.id === id);
    const wouldCycle = (pred, succ) => {
        let cur = pred;
        for (let i = 0; cur && i < 100; i++) {
            if (cur.depends_on_id === succ.id) return true;
            cur = cur.depends_on_id ? byId(cur.depends_on_id) : null;
        }
        return false;
    };

    const chains = [];
    for (const r of list) {
        const t = r.kind === 'task' ? r.item : null;
        if (!t?.depends_on_id || !(t.depends_on_id in yMid)) continue;
        const pred = byId(t.depends_on_id);
        if (!pred) continue;
        chains.push({ succ: t, pred, x1: X(end(pred)), y1: yMid[pred.id], x2: X(start(t)), y2: yMid[t.id] });
    }

    const pt = (e) => {
        const r = canvasRef.current.getBoundingClientRect();
        return { xPct: ((e.clientX - r.left) / r.width) * 100, yPx: e.clientY - r.top };
    };
    const rowAt = (yPx) => listRef.current.find((r) => yPx >= r.y && yPx < r.y + ROW_H[r.kind]);

    useEffect(() => {
        if (!drag) return;
        const mv = (e) => {
            const cur = pt(e);
            setDrag((d) => {
                if (!d) return d;
                // Axis lock: horizontal = replan dates, vertical = refile/chain target.
                let axis = d.axis;
                if (!axis && d.type === 'bar') {
                    const dx = Math.abs(cur.xPct - d.x0), dy = Math.abs(cur.yPx - d.y0);
                    if (dx * 8 > 1 || dy > 6) axis = (dx / 100 * (canvasRef.current?.clientWidth || 1000)) > dy ? 'time' : 'row';
                }
                return { ...d, ...cur, axis };
            });
        };
        const up = (e) => {
            const { xPct, yPx } = pt(e);
            const d = drag;
            setDrag(null);
            if (!d) return;
            if (d.type === 'link') {
                const target = rowAt(yPx);
                if (target?.kind === 'task' && target.item.id !== d.source.id && !wouldCycle(d.source, target.item)) {
                    patch(target.item.id, { depends_on_id: d.source.id });
                }
                return;
            }
            // bar drag: settle by locked axis
            if (d.axis === 'time' && !d.source.done) {
                const deltaDays = Math.round((xPct - d.x0) / 100 * total);
                if (deltaDays !== 0) {
                    const s0 = start(d.source), e0 = end(d.source);
                    patch(d.source.id, {
                        planned_start: ymd(addDays(s0, deltaDays)),
                        due_date: ymd(addDays(e0, deltaDays)),
                    });
                }
            } else if (d.axis === 'row') {
                const target = rowAt(yPx);
                if (!target) return;
                if (target.kind === 'project' && target.item.id !== d.source.parent_id) {
                    patch(d.source.id, { parent_id: target.item.id });
                } else if (target.kind === 'task' && target.item.parent_id && projectIds.has(target.item.parent_id) && target.item.parent_id !== d.source.parent_id) {
                    patch(d.source.id, { parent_id: target.item.parent_id });
                } else if ((target.kind === 'sect' || (target.kind === 'task' && !target.item.parent_id)) && d.source.parent_id) {
                    patch(d.source.id, { parent_id: null });
                }
            }
        };
        window.addEventListener('pointermove', mv);
        window.addEventListener('pointerup', up, { once: true });
        return () => { window.removeEventListener('pointermove', mv); window.removeEventListener('pointerup', up); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [drag?.type, drag?.source?.id]);

    if (!list.length) {
        return <p className="py-8 text-sm text-gray-400 text-center">Nothing to chart yet — add a project or some tasks.</p>;
    }

    const hoverRow = drag && (drag.type === 'link' || drag.axis === 'row') ? rowAt(drag.yPx ?? -1) : null;
    const hoverValid = hoverRow && (
        drag?.type === 'link'
            ? (hoverRow.kind === 'task' && hoverRow.item.id !== drag.source.id && !wouldCycle(drag.source, hoverRow.item))
            : true
    );
    const timeDelta = drag?.axis === 'time' ? Math.round(((drag.xPct ?? drag.x0) - drag.x0) / 100 * total) : 0;

    return (
        <div className="overflow-x-auto">
            <div className={`min-w-[860px] border border-gray-200 dark:border-gray-800 rounded-lg overflow-hidden ${drag ? 'select-none' : ''}`}>
                <div className="flex items-stretch bg-gray-100 dark:bg-gray-800/70 text-[11px] uppercase tracking-wide text-gray-400">
                    <div className="w-56 shrink-0 px-3 py-2">Project / Task</div>
                    <div className="relative flex-1 py-2">
                        {weeks.map((w, i) => (
                            <span key={i} className="absolute pl-1 whitespace-nowrap" style={{ left: `${X(w)}%` }}>{fmt(w)}</span>
                        ))}
                    </div>
                </div>

                <div className="flex">
                    <div className="w-56 shrink-0">
                        {list.map((r, i) => (
                            <div key={i} style={{ height: ROW_H[r.kind], paddingLeft: r.kind === 'task' ? 12 + 16 * (r.level + 1) : 12 }}
                                onClick={r.kind === 'project' ? () => onOpenProject?.(r.item.id) : undefined}
                                title={r.kind === 'project' ? 'Open project details' : undefined}
                                className={`pr-3 flex items-center text-sm truncate border-b border-gray-50 dark:border-gray-800/70 ${
                                    r.kind === 'project' ? 'font-medium text-gray-800 dark:text-gray-100 cursor-pointer hover:text-blue-600 dark:hover:text-blue-400'
                                    : r.kind === 'sect' ? 'bg-gray-50 dark:bg-gray-900/60 text-[11px] uppercase tracking-wide text-gray-400'
                                    : 'text-gray-500 dark:text-gray-400'}`}>
                                {r.kind === 'sect' ? r.label : <>{r.kind === 'task' && r.level > 0 && <span className="mr-1 text-gray-300 dark:text-gray-600">↳</span>}{r.item.title}</>}
                            </div>
                        ))}
                    </div>
                    <div ref={canvasRef} className="relative flex-1" style={{ height: bodyH }}>
                        {weeks.map((w, i) => (
                            <div key={i} className="absolute inset-y-0 border-l border-gray-100 dark:border-gray-800/60" style={{ left: `${X(w)}%` }} />
                        ))}
                        <div className="absolute inset-y-0 border-l-2 border-red-400/70" style={{ left: `${X(today)}%` }} title="today" />

                        {hoverRow && (
                            <div className={`absolute inset-x-0 ${hoverValid ? 'bg-blue-100/60 dark:bg-blue-500/15' : 'bg-red-100/40 dark:bg-red-500/10'}`}
                                style={{ top: hoverRow.y, height: ROW_H[hoverRow.kind] }} />
                        )}

                        {list.map((r, i) => {
                            if (r.kind === 'sect') return <div key={i} className="absolute inset-x-0 bg-gray-50 dark:bg-gray-900/60" style={{ top: r.y, height: ROW_H.sect }} />;
                            const t = r.item;
                            const isProject = r.kind === 'project';
                            const dragging = drag?.type === 'bar' && drag.axis === 'time' && drag.source.id === t.id && !t.done;
                            const shift = dragging ? timeDelta : 0;
                            const s = addDays(isProject ? r.s : start(t), shift);
                            const e = addDays(isProject ? r.e : end(t), shift);
                            const cls = isProject
                                ? `${GANTT_STATUS_BAR[t.status || (t.pct >= 100 ? 'Done' : t.pct > 0 ? 'In progress' : 'Proposed')] || GANTT_STATUS_BAR.Proposed} opacity-90`
                                : `${t.done ? 'bg-gray-300 dark:bg-gray-600' : GANTT_PRI_BAR[t.pri] || GANTT_PRI_BAR[0]} opacity-80`;
                            return (
                                <div key={i} className="absolute inset-x-0 group/lane" style={{ top: r.y, height: ROW_H[r.kind] }}>
                                    {/* origin tick: where the task was born — never moves */}
                                    {!isProject && t.origin && (
                                        <span className="absolute h-2 w-2 rotate-45 bg-gray-400 dark:bg-gray-500" title={`Origin ${t.origin} (fixed)`}
                                            style={{ left: `calc(${X(parseYmd(t.origin))}% - 4px)`, top: '50%', transform: 'translateY(-50%) rotate(45deg)' }} />
                                    )}
                                    <div className={`absolute rounded ${cls} ${isProject ? 'h-5' : 'h-3 cursor-grab active:cursor-grabbing'} ${overdue(t) ? 'ring-2 ring-red-500' : ''} ${dragging ? 'opacity-60' : ''}`}
                                        style={{ left: `${X(s)}%`, width: `${Math.max(((e - s) / 86400000 + 1) / total * 100, 1.2)}%`, top: '50%', transform: 'translateY(-50%)' }}
                                        title={`${t.title} · ${t.pct || 0}%${isProject ? '' : t.done ? '' : ' — drag ↔ to replan, ↕ onto a project to refile, ○ to chain'}`}
                                        onPointerDown={isProject ? undefined : (ev) => { ev.preventDefault(); const c = pt(ev); setDrag({ type: 'bar', source: t, x0: c.xPct, y0: c.yPx, ...c }); }}>
                                        {(t.pct || 0) > 0 && <div className="absolute inset-y-0 left-0 rounded bg-black/20" style={{ width: `${Math.min(t.pct, 100)}%` }} />}
                                        <span onPointerDown={(ev) => { ev.stopPropagation(); ev.preventDefault(); const c = pt(ev); setDrag({ type: 'link', source: t, x1: X(e), y1: yMid[t.id], ...c }); }}
                                            title="drag to another bar: it will run AFTER this"
                                            className="absolute -right-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 rounded-full border-2 border-gray-400 bg-white dark:bg-gray-900 opacity-0 group-hover/lane:opacity-100 cursor-crosshair" />
                                    </div>
                                </div>
                            );
                        })}

                        {chains.map((c) => (
                            <FragmentRows key={`${c.pred.id}-${c.succ.id}`}>
                                <div className="absolute border-t-2 border-dotted border-gray-400/80 pointer-events-none" style={{ top: c.y1, left: `${Math.min(c.x1, c.x2)}%`, width: `${Math.max(Math.abs(c.x2 - c.x1), 0.4)}%` }} />
                                <div className="absolute border-l-2 border-dotted border-gray-400/80 pointer-events-none" style={{ left: `${c.x2}%`, top: Math.min(c.y1, c.y2), height: Math.abs(c.y2 - c.y1) - 6 }} />
                                <button onClick={() => { if (confirm(`Unlink "${c.succ.title}" from "${c.pred.title}"?`)) patch(c.succ.id, { depends_on_id: null }); }}
                                    title="click to unlink"
                                    className="absolute h-0 w-0 border-x-4 border-x-transparent border-t-[6px] border-t-gray-400 hover:border-t-red-500 cursor-pointer"
                                    style={{ left: `calc(${c.x2}% - 3.5px)`, top: c.y2 - 8 }} />
                            </FragmentRows>
                        ))}

                        {drag?.type === 'link' && drag.xPct != null && (
                            <>
                                <div className="absolute border-t-2 border-dotted border-blue-500 pointer-events-none" style={{ top: drag.y1, left: `${Math.min(drag.x1, drag.xPct)}%`, width: `${Math.abs(drag.xPct - drag.x1)}%` }} />
                                <div className="absolute border-l-2 border-dotted border-blue-500 pointer-events-none" style={{ left: `${drag.xPct}%`, top: Math.min(drag.y1, drag.yPx), height: Math.abs(drag.yPx - drag.y1) }} />
                            </>
                        )}
                        {drag?.axis === 'time' && timeDelta !== 0 && (
                            <div className="absolute rounded bg-gray-800 text-white text-[11px] px-1.5 py-0.5 pointer-events-none" style={{ left: `${drag.xPct}%`, top: Math.max(drag.yPx - 26, 0) }}>
                                {timeDelta > 0 ? '+' : ''}{timeDelta}d
                            </div>
                        )}
                    </div>
                </div>
            </div>
            <p className="mt-3 text-xs text-gray-400">
                Drag a bar ↔ to replan (◆ = origin, fixed — you can move the plan, not the past) or ↕ onto a project to refile.
                Drag the ○ to chain ("runs after"); click an arrowhead to unlink. Red ring = overdue. Darker fill = % complete. Red line = today.
            </p>
        </div>
    );
}
