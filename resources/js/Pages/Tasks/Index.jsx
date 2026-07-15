import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import SearchSelect from '@/Components/SearchSelect';
import { PlusIcon, TrashIcon } from '@/Components/Icons';

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const api = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then((r) => (r.status === 204 ? {} : r.json()));

const PRI = ['·', 'Low', 'Med', 'High'];
const PRI_DOT = ['bg-gray-300', 'bg-blue-500', 'bg-amber-500', 'bg-red-500'];

// week is a Monday ISO date — build "Jul 13 – Jul 19" from it without timezone drift.
function weekLabel(iso) {
    if (!iso) return 'Undated';
    const [y, m, d] = iso.split('-').map(Number);
    const start = new Date(y, m - 1, d);
    const end = new Date(y, m - 1, d + 6);
    const f = (dt) => dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    return `${f(start)} – ${f(end)}, ${end.getFullYear()}`;
}
// current Monday as ISO, computed locally.
function thisMonday() {
    const now = new Date();
    const day = (now.getDay() + 6) % 7; // 0 = Monday
    const mon = new Date(now.getFullYear(), now.getMonth(), now.getDate() - day);
    return `${mon.getFullYear()}-${String(mon.getMonth() + 1).padStart(2, '0')}-${String(mon.getDate()).padStart(2, '0')}`;
}

export default function Index() {
    const [tasks, setTasks] = useState([]);
    const [statuses, setStatuses] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [detail, setDetail] = useState(null);
    const [status, setStatus] = useState('');
    const timers = useRef({});

    const load = () => api('/data/tasks').then((r) => { setTasks(r.tasks); setStatuses(r.statuses); });
    useEffect(() => { load(); }, []);
    useEffect(() => {
        if (!selectedId) { setDetail(null); return; }
        api(`/data/tasks/${selectedId}`).then(setDetail);
    }, [selectedId]);

    // group by week, newest week first; incomplete before complete inside a week.
    const groups = useMemo(() => {
        const by = {};
        for (const t of tasks) (by[t.week] ||= []).push(t);
        return Object.keys(by).sort((a, b) => (a < b ? 1 : -1)).map((week) => ({
            week,
            items: by[week].sort((a, b) => (a.done - b.done) || (b.pri - a.pri) || (a.ord - b.ord)),
        }));
    }, [tasks]);

    const flash = (msg = 'Saved') => { setStatus(msg); clearTimeout(timers.current._s); timers.current._s = setTimeout(() => setStatus(''), 1200); };

    // optimistic patch: update list + detail immediately, persist (debounced for free-text).
    const patch = (id, changes, { debounce } = {}) => {
        setTasks((ts) => ts.map((t) => (t.id === id ? { ...t, ...changes } : t)));
        setDetail((d) => (d && d.id === id ? { ...d, ...changes } : d));
        const send = () => { setStatus('Saving…'); api(`/data/tasks/${id}`, 'PATCH', changes).then(() => { flash(); load(); }); };
        if (debounce) { clearTimeout(timers.current[id]); timers.current[id] = setTimeout(send, 500); }
        else send();
    };

    const newTask = async () => {
        const { id } = await api('/data/tasks', 'POST', { title: 'New task', week: thisMonday() });
        await load();
        setSelectedId(id);
    };
    const del = async () => {
        if (!confirm('Delete this task?')) return;
        await api(`/data/tasks/${selectedId}`, 'DELETE');
        setSelectedId(null); load();
    };

    const nav = (
        <div className="flex flex-col h-full">
            <div className="p-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <span className="text-sm font-semibold text-gray-700 dark:text-gray-200">Tasks</span>
                <button onClick={newTask} className="text-xs rounded-md bg-blue-600 text-white px-2 py-1 hover:bg-blue-700 inline-flex items-center gap-1"><PlusIcon /> New</button>
            </div>
            <div className="flex-1 overflow-y-auto">
                {groups.length === 0 && <div className="p-4 text-sm text-gray-400">No tasks yet. Create one.</div>}
                {groups.map((g) => (
                    <div key={g.week}>
                        <div className="sticky top-0 bg-gray-100/90 dark:bg-gray-900/90 backdrop-blur px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800">
                            {weekLabel(g.week)}
                        </div>
                        {g.items.map((t) => (
                            <TaskRow key={t.id} t={t} selected={selectedId === t.id}
                                onSelect={() => setSelectedId(t.id)}
                                onToggle={(done) => patch(t.id, { done })} />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );

    const detailPane = detail ? (
        <TaskDetail key={detail.id} t={detail} statuses={statuses} patch={patch} onDelete={del} />
    ) : (
        <div className="h-full flex items-center justify-center text-gray-400 text-sm">Select or create a task</div>
    );

    const doneCount = tasks.filter((t) => t.done).length;
    return (
        <>
            <Head title="Tasks" />
            <AppShell active="tasks" nav={nav} detail={detailPane}
                footer={<div className="flex w-full justify-between"><span>Tasks — {doneCount} of {tasks.length} done</span><span className="text-gray-400">{status}</span></div>} />
        </>
    );
}

function TaskRow({ t, selected, onSelect, onToggle }) {
    return (
        <div className={`group flex items-center gap-2 px-3 py-2 border-b border-gray-100 dark:border-gray-800 cursor-pointer ${selected ? 'bg-blue-50 dark:bg-blue-500/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60'}`}
            onClick={onSelect}>
            <input type="checkbox" checked={t.done} onClick={(e) => e.stopPropagation()} onChange={(e) => onToggle(e.target.checked)}
                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
            <span className={`h-2 w-2 shrink-0 rounded-full ${PRI_DOT[t.pri] || PRI_DOT[0]}`} title={PRI[t.pri]} />
            <div className="flex-1 min-w-0">
                <div className={`text-sm truncate ${t.done ? 'line-through text-gray-400' : 'text-gray-800 dark:text-gray-100'}`}>
                    {t.is_project && <span className="mr-1.5 align-middle text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">Project</span>}
                    {t.title}
                </div>
                <div className="flex items-center gap-2 text-xs text-gray-400">
                    {t.assignee && <span className="truncate">{t.assignee}</span>}
                    {t.is_project && t.status && <span>· {t.status}</span>}
                    {!t.done && t.pct > 0 && <span>· {t.pct}%</span>}
                </div>
            </div>
        </div>
    );
}

function TaskDetail({ t, statuses, patch, onDelete }) {
    const set = (changes, opts) => patch(t.id, changes, opts);
    return (
        <div className="max-w-3xl mx-auto">
            <div className="flex items-start justify-between gap-4 mb-4">
                <input value={t.title} onChange={(e) => set({ title: e.target.value }, { debounce: true })}
                    className="flex-1 text-2xl font-bold text-gray-900 dark:text-white border-0 focus:ring-0 px-0 bg-transparent placeholder-gray-300" placeholder="Task title" />
                <button onClick={onDelete} title="Delete task" className="mt-2 shrink-0 inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600"><TrashIcon /></button>
            </div>

            <div className="grid grid-cols-2 gap-4 mb-4">
                <Field label="Week">
                    <input type="date" value={t.week || ''} onChange={(e) => set({ week: e.target.value })}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
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

            <div className="flex items-center gap-6 mb-6 text-sm">
                <label className="inline-flex items-center gap-2 text-gray-700 dark:text-gray-200">
                    <input type="checkbox" checked={t.done} onChange={(e) => set({ done: e.target.checked })}
                        className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" /> Done
                </label>
                <label className="inline-flex items-center gap-2 text-gray-700 dark:text-gray-200">
                    <input type="checkbox" checked={t.is_project} onChange={(e) => set({ is_project: e.target.checked })}
                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Track as project
                </label>
            </div>

            {t.is_project && (
                <div className="rounded-lg border border-gray-200 dark:border-gray-800 p-4 space-y-4">
                    <Field label="Status">
                        <select value={t.status || ''} onChange={(e) => set({ status: e.target.value })}
                            className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">—</option>
                            {statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </Field>
                    {[
                        ['details', 'Details'], ['impact', 'Impact'], ['needs', 'Needs'],
                        ['challenges', 'Challenges'], ['workarounds', 'Workarounds'],
                    ].map(([k, label]) => (
                        <Field key={k} label={label}>
                            <textarea rows={2} value={t[k] || ''} onChange={(e) => set({ [k]: e.target.value }, { debounce: true })}
                                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                        </Field>
                    ))}
                </div>
            )}
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
