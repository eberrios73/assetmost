import { useEffect, useState } from 'react';
import AddButton from '@/Components/ui/AddButton';
import { MultiPicker } from '@/Components/RecordModal';
import SearchSelect from '@/Components/SearchSelect';

const CATEGORIES = ['accounts', 'machine', 'access', 'training', 'other'];

const uid = () => Math.random().toString(36).slice(2, 10);

/** Light heuristics only — the human rearranges after. */
function guessCategory(title) {
    const t = title.toLowerCase();
    if (/\b(ad|domain|dc\d|active directory|mailbox|365|microsoft|zoom|adobe|moodle|account|email|license)\b/.test(t)) return 'accounts';
    if (/\b(machine|laptop|computer|image|imaging|hardware|dock|monitor|phone|setup)\b/.test(t)) return 'machine';
    if (/\b(vpn|wifi|badge|door|key|access|permissions|group|share)\b/.test(t)) return 'access';
    if (/\b(training|security awareness|handbook|orientation)\b/.test(t)) return 'training';
    return 'other';
}

/**
 * Paste the company's existing SOP; every line becomes a step, indented (or
 * bulleted-under) lines become subtasks. No AI required — the SOP's own wording
 * IS the instruction text, and the human rearranges. The saved JSON is what the
 * run-wizard turns into a chained task project per new hire.
 */
function parseSop(text) {
    const steps = [];
    const mk = (title) => ({ id: uid(), title, instructions: '', category: guessCategory(title), automatable: false, subtasks: [] });
    for (const raw of text.replace(/\t/g, '    ').split('\n')) {
        if (!raw.trim()) continue;
        const indent = raw.match(/^ */)[0].length;
        let line = raw.trim();
        // Long prose = context, not a step: intro is skipped, later prose joins the last step's How.
        if (line.split(/\s+/).length > 20 && !/^[☐□☑✓o§·▪-]/.test(line)) {
            const last = steps[steps.length - 1];
            if (last) last.instructions = (last.instructions ? last.instructions + '\n' : '') + line;
            continue;
        }
        // Checkbox lines are items; inner checkboxes split into several ("☐ Ram ☐ HD" = two).
        if (/^[☐□☑✓]/.test(line)) {
            const parts = line.split(/[☐□☑✓]/).map((x) => x.trim()).filter(Boolean);
            const parent = steps[steps.length - 1];
            for (const part of parts) (parent ? parent.subtasks : steps).push(mk(part));
            continue;
        }
        const title = line.replace(/^([-*•·o§▪]|\d+[.)]|[a-zA-Z][.)])\s+/, '').trim();
        if (!title) continue;
        const step = mk(title);
        if (indent >= 2 && steps.length) steps[steps.length - 1].subtasks.push(step);
        else steps.push(step);
    }
    return steps;
}

const api = async (url, method = 'GET', body) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const res = await fetch(url, {
        method, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.ok ? res.json().catch(() => ({})) : Promise.reject(await res.json().catch(() => ({})));
};

/**
 * The onboarding editor, tabbed so a long runbook isn't one endless scroll:
 *   Info   — the run wizard (hires), the SOP source/metadata, task preview, or the
 *            adopt/compile/paste sources when there are no steps yet.
 *   Steps  — the step editor (the checklist that becomes the task project).
 *   Script — for machine runbooks (Workstation setup), the bootstrap script the SOP
 *            produces, editable. Employee runbooks make a task project, not a script.
 * kind/variant are controlled by the left-column list in Workspace.
 */
export default function OnboardingSetup({ kind, variant, onVariant }) {
    const [meta, setMeta] = useState({ kinds: {}, existing: [] });
    const [loaded, setLoaded] = useState(false);
    const [steps, setSteps] = useState(null);        // null = nothing saved for this kind/variant
    const [source, setSource] = useState(null);      // {id,title} of the master Docs page
    const [sopMeta, setSopMeta] = useState(null);    // {owner, version, status, ...} from the SOP's governance table
    const [preview, setPreview] = useState(null);    // 'load' | {rows:[...]}
    const [pasting, setPasting] = useState(false);
    const [text, setText] = useState('');
    const [saved, setSaved] = useState('');
    const [tab, setTab] = useState('steps');         // info | steps | script
    // Switching procedures leaves paste mode and clears any stale preview.
    useEffect(() => { setPasting(false); setPreview(null); }, [kind]);

    useEffect(() => {
        if (preview !== 'load') return;
        api(`/data/onboarding-preview?kind=${kind}&variant=${encodeURIComponent(variant)}`).then((r) => setPreview(r)).catch(() => setPreview(null));
    }, [preview, kind, variant]);

    const load = (k = kind, v = variant) => {
        setLoaded(false); setPreview(null);
        api(`/data/onboarding-template?kind=${k}&variant=${encodeURIComponent(v)}`)
            .then((r) => { setMeta({ kinds: r.kinds, existing: r.existing }); setSteps(r.steps?.steps ?? null); setSource(r.source ?? null); setSopMeta(r.sop_meta ?? null); setLoaded(true); });
    };
    useEffect(() => { load(kind, variant); }, [kind, variant]);

    const save = async (next) => {
        setSteps(next);
        await api('/data/onboarding-template', 'PUT', { kind, variant, steps: { version: 1, steps: next } });
        setSaved('Saved'); setTimeout(() => setSaved(''), 1200);
    };
    const adoptStarter = async () => { await api('/data/onboarding-adopt-starter', 'POST', { kind, variant }); load(kind, variant); };
    const parseFromDoc = async (pageId) => { await api('/data/onboarding-parse-doc', 'POST', { page_id: pageId, kind, variant }); load(kind, variant); };

    const variants = ['', ...new Set(meta.existing.filter((e) => e.kind === kind && e.variant).map((e) => e.variant))];
    const newVariant = () => { const v = prompt('Department variant name (e.g. Design):'); if (v?.trim()) onVariant(v.trim()); };

    // list surgery helpers — operate on top-level or a parent's subtasks uniformly
    const replace = (list, id, fn) => list.map((s) => (s.id === id ? fn(s) : { ...s, subtasks: replace(s.subtasks || [], id, fn) }));
    const removeById = (list, id) => list.filter((s) => s.id !== id).map((s) => ({ ...s, subtasks: removeById(s.subtasks || [], id) }));
    const move = (list, i, dir) => { const n = [...list]; const j = i + dir; if (j < 0 || j >= n.length) return list; [n[i], n[j]] = [n[j], n[i]]; return n; };

    const variantSelector = (
        <div className="flex items-center gap-2">
            <span className="text-xs uppercase tracking-wide text-gray-400">Variant</span>
            <select value={variant} onChange={(e) => e.target.value === '__new__' ? newVariant() : onVariant(e.target.value)}
                className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 text-sm py-1.5 text-gray-600 dark:text-gray-300">
                {variants.map((v) => <option key={v} value={v}>{v || 'Default'}</option>)}
                <option value="__new__">+ Department variant…</option>
            </select>
        </div>
    );

    const tabBar = (
        <div className="flex rounded-lg border border-gray-200 dark:border-gray-700 p-0.5 text-sm">
            {[['info', 'Info'], ['steps', 'Steps'], ['script', 'Script']].map(([k, label]) => (
                <button key={k} onClick={() => setTab(k)}
                    className={`px-3 py-1 rounded-md ${tab === k ? 'bg-blue-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'}`}>
                    {label}
                </button>
            ))}
        </div>
    );

    if (!loaded) return <div className="max-w-3xl"><p className="text-sm text-gray-400 py-6">Loading…</p></div>;

    return (
        <div className="max-w-3xl">
            <div className="mb-4 flex items-center justify-between gap-3">
                {tabBar}
                <div className="flex items-center gap-2">
                    {saved && <span className="text-xs text-green-600">{saved}</span>}
                    {variantSelector}
                </div>
            </div>

            {tab === 'info' && (
                <div className="space-y-4">
                    <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">
                        {meta.kinds[kind]}{variant ? ` — ${variant}` : ''}
                    </h2>
                    {kind === 'onboarding' && <RunCard />}
                    {(steps === null || pasting) ? (
                        <PasteSources
                            pasting={pasting} kindLabel={meta.kinds[kind]?.toLowerCase()}
                            text={text} setText={setText}
                            onParse={() => { const parsed = parseSop(text); if (parsed.length) { save(parsed); setPasting(false); setText(''); setTab('steps'); } }}
                            onCancel={steps !== null ? () => setPasting(false) : null}
                            onPickDoc={(id) => { if (id) { parseFromDoc(id); setTab('steps'); } }}
                            onAdopt={() => { adoptStarter(); setTab('steps'); }} />
                    ) : (
                        <SopSummary source={source} sopMeta={sopMeta} preview={preview} setPreview={setPreview}
                            onReparse={() => parseFromDoc(source.id)} />
                    )}
                </div>
            )}

            {tab === 'steps' && (
                steps === null ? (
                    <div className="rounded-lg border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        No steps yet. Head to <button onClick={() => setTab('info')} className="text-blue-600 hover:underline">Info</button> to adopt the standard SOP, compile from a doc, or paste your own.
                    </div>
                ) : (
                    <div>
                        <p className="mb-3 text-sm text-gray-500 dark:text-gray-400">
                            Steps become the task project, chained in this order. Placeholders
                            <code className="mx-1 text-xs bg-gray-100 dark:bg-gray-800 rounded px-1">{'{first} {last} {username} {email} {start_date} {local_domain} {domain}'}</code>
                            fill in at run time{kind === 'offboarding' ? ' ({start_date} = last day)' : ''}.
                        </p>
                        <div className="mb-3 flex items-center gap-2">
                            <AddButton label="Add step" onClick={() => save([...steps, { id: uid(), title: 'New step', instructions: '', category: 'other', automatable: false, subtasks: [] }])} />
                            {source && (
                                <button onClick={() => parseFromDoc(source.id)}
                                    className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                                    title="Re-compile the steps from the doc — the doc wins over manual edits here">Re-parse from doc</button>
                            )}
                            <button onClick={() => { setPasting(true); setTab('info'); }}
                                className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Re-paste SOP</button>
                        </div>
                        <ol className="space-y-2">
                            {steps.map((s, i) => (
                                <StepCard key={s.id} step={s} index={i} count={steps.length}
                                    onChange={(fn) => save(replace(steps, s.id, fn))}
                                    onRemove={() => save(removeById(steps, s.id))}
                                    onMove={(dir) => save(move(steps, i, dir))}
                                    onAddSub={() => save(replace(steps, s.id, (x) => ({ ...x, subtasks: [...(x.subtasks || []), { id: uid(), title: 'New subtask', instructions: '', category: s.category, automatable: false, subtasks: [] }] })))}
                                    renderSub={(sub, j) => (
                                        <StepCard key={sub.id} step={sub} index={j} count={(s.subtasks || []).length} nested
                                            onChange={(fn) => save(replace(steps, sub.id, fn))}
                                            onRemove={() => save(removeById(steps, sub.id))}
                                            onMove={(dir) => save(replace(steps, s.id, (x) => ({ ...x, subtasks: move(x.subtasks, j, dir) })))} />
                                    )} />
                            ))}
                        </ol>
                    </div>
                )
            )}

            {tab === 'script' && <ScriptPanel kind={kind} variant={variant} />}
        </div>
    );
}

/** The adopt / compile-from-doc / paste sources (shown when there are no steps yet). */
function PasteSources({ pasting, kindLabel, text, setText, onParse, onCancel, onPickDoc, onAdopt }) {
    return (
        <div className="space-y-3">
            {!pasting && (
                <>
                    <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                        <p className="text-sm font-medium text-gray-800 dark:text-gray-100 mb-1">Compile from a Docs page</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                            Your SOP doc is the master — pick it and it parses into steps. Edit the doc later, hit re-parse, the template follows.
                        </p>
                        <SearchSelect value={null} endpoint="/data/doc-options" placeholder="Search your Docs pages…" onChange={onPickDoc} />
                    </div>
                    <div className="rounded-lg border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-500/10 p-4">
                        <p className="text-sm text-blue-800 dark:text-blue-300 mb-3">
                            No SOP yet? Adopt the standard {kindLabel} one — it's created as a real Docs page in your wiki, then compiled from there. Yours to edit like any doc.
                        </p>
                        <AddButton label="Use the standard SOP" onClick={onAdopt} />
                    </div>
                </>
            )}
            <p className="text-sm text-gray-500 dark:text-gray-400">
                Or paste your current SOP — every line becomes a step, indented lines become subtasks; your wording is kept verbatim.
            </p>
            <textarea rows={12} value={text} onChange={(e) => setText(e.target.value)}
                placeholder={"Create AD account on DC01\nCreate M365 mailbox\nSet up their machine\n    Image with standard build\n    Join to domain\nSecurity training"}
                className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm font-mono focus:border-blue-500 focus:ring-blue-500" />
            <div className="flex gap-2">
                <AddButton label="Parse into steps" onClick={onParse} />
                {onCancel && <button onClick={onCancel} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">Cancel</button>}
            </div>
        </div>
    );
}

/** SOP source + governance metadata, and the task preview (what the steps become). */
function SopSummary({ source, sopMeta, preview, setPreview, onReparse }) {
    return (
        <div className="space-y-3">
            {source ? (
                <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 flex items-center justify-between gap-3">
                    <div>
                        <a href={`/docs?page=${source.id}`} className="text-sm text-blue-600 dark:text-blue-400 hover:underline" title="The master SOP document">
                            Master SOP: {source.title}
                        </a>
                        {sopMeta && (sopMeta.version || sopMeta.owner || sopMeta.status) && (
                            <span className="ml-2 text-xs text-gray-400">
                                {sopMeta.version ? `v${sopMeta.version}` : ''}{sopMeta.owner ? ` · ${sopMeta.owner}` : ''}
                                {sopMeta.status && (
                                    <span className={`ml-1 rounded px-1.5 py-0.5 text-[10px] font-medium ${/approved|active/i.test(sopMeta.status) ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'}`}>
                                        {sopMeta.status}
                                    </span>
                                )}
                            </span>
                        )}
                    </div>
                    <button onClick={onReparse}
                        className="shrink-0 px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                        title="Re-compile the steps from the doc — the doc wins over manual edits here">Re-parse from doc</button>
                </div>
            ) : (
                <p className="text-sm text-gray-500 dark:text-gray-400">These steps were entered directly (no linked Docs page).</p>
            )}

            <div>
                <button onClick={() => setPreview(preview ? null : 'load')}
                    className="px-3 py-1.5 text-sm rounded-md border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-500/10">
                    {preview ? 'Hide task preview' : 'Preview tasks'}
                </button>
                {preview === 'load' && <p className="mt-3 text-sm text-gray-400">Building preview…</p>}
                {preview && preview !== 'load' && (
                    <div className="mt-3 rounded-lg border border-blue-200 dark:border-blue-900 bg-blue-50/50 dark:bg-blue-500/5 p-4">
                        <p className="text-sm font-medium text-blue-800 dark:text-blue-300 mb-2">
                            The checklist this becomes — {preview.rows.length} tasks. Steps pulled from a referenced runbook
                            (like <code>/eprotection</code>) are marked <span className="rounded bg-amber-100 dark:bg-amber-500/15 px-1 text-[10px] text-amber-700 dark:text-amber-400">linked</span> and stay current.
                        </p>
                        <ol className="space-y-0.5">
                            {preview.rows.map((r, i) => (
                                <li key={i} className={`flex items-start gap-2 text-sm ${r.depth ? 'pl-6 text-gray-500 dark:text-gray-400' : 'text-gray-800 dark:text-gray-100 font-medium'}`}>
                                    <span className="text-gray-300 dark:text-gray-600">{r.depth ? '↳' : '•'}</span>
                                    <span>{r.title}{r.ref && <span className="ml-2 rounded bg-amber-100 dark:bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-normal text-amber-700 dark:text-amber-400">linked</span>}</span>
                                </li>
                            ))}
                        </ol>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * The Script tab. Employee runbooks build a task project, not a script. Machine runbooks
 * (Workstation setup / imaging) produce the actual bootstrap script from the SOP — the
 * device specifics stay as placeholders, but the SOP's /install, /vpn and /mdm resolve
 * for real. Editable here (persisting edits is the next step).
 */
function ScriptPanel({ kind, variant }) {
    const [script, setScript] = useState('');
    const [loading, setLoading] = useState(false);
    const [copied, setCopied] = useState(false);

    const gen = () => {
        setLoading(true);
        api(`/data/onboarding-script?variant=${encodeURIComponent(variant)}`)
            .then((r) => setScript(r.script || ''))
            .catch((e) => setScript(`# ${Object.values(e?.errors || {}).flat()[0] || e?.message || 'Could not generate a script for this runbook.'}`))
            .finally(() => setLoading(false));
    };
    useEffect(() => { if (kind === 'imaging') gen(); else setScript(''); }, [kind, variant]);

    if (kind === 'onboarding' || kind === 'freelancer' || kind === 'offboarding') {
        return <p className="text-sm text-gray-500 dark:text-gray-400">Employee onboarding builds a chained <strong>task project</strong>, not a machine script — see <em>Preview tasks</em> under Info.</p>;
    }
    if (kind === 'eprotection') {
        return <p className="text-sm text-gray-500 dark:text-gray-400">This runbook is pulled into others with <code>/eprotection</code> and doesn't produce a standalone machine script.</p>;
    }

    return (
        <div className="space-y-2">
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    Generated from this runbook's steps — <code>{'{ASSET_TAG}'}</code> and friends fill in per machine; the SOP's
                    <code className="mx-1">/install</code><code className="mr-1">/vpn</code><code>/mdm</code> resolve for real. Editable here (saving edits comes next).
                </p>
                <div className="flex gap-2 shrink-0">
                    <button onClick={gen} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Regenerate</button>
                    <button onClick={() => { navigator.clipboard?.writeText(script); setCopied(true); setTimeout(() => setCopied(false), 1200); }}
                        className="px-3 py-1.5 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700">{copied ? 'Copied ✓' : 'Copy'}</button>
                </div>
            </div>
            {loading ? <p className="text-sm text-gray-400 py-6">Generating…</p> : (
                <textarea value={script} onChange={(e) => setScript(e.target.value)} rows={22} spellCheck={false}
                    className="w-full rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-950 p-4 font-mono text-xs leading-relaxed text-green-300 focus:border-blue-500 focus:ring-blue-500" />
            )}
        </div>
    );
}

function StepCard({ step: s, index, count, nested = false, onChange, onRemove, onMove, onAddSub, renderSub }) {
    const [open, setOpen] = useState(false);
    return (
        <li className={`rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 ${nested ? 'ml-8' : ''}`}>
            <div className="flex items-center gap-2 p-2.5">
                <span className="w-6 text-center text-xs text-gray-400">{index + 1}</span>
                <input value={s.title} onChange={(e) => onChange((x) => ({ ...x, title: e.target.value }))}
                    className="flex-1 border-0 bg-transparent p-0 text-sm text-gray-800 dark:text-gray-100 focus:ring-0" />
                <select value={s.category} onChange={(e) => onChange((x) => ({ ...x, category: e.target.value }))}
                    className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 text-xs py-1 text-gray-500 dark:text-gray-400">
                    {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
                <button onClick={() => onMove(-1)} disabled={index === 0} className="px-1 text-gray-400 hover:text-gray-700 disabled:opacity-30">↑</button>
                <button onClick={() => onMove(1)} disabled={index === count - 1} className="px-1 text-gray-400 hover:text-gray-700 disabled:opacity-30">↓</button>
                <button onClick={() => setOpen((o) => !o)} title="instructions" className={`px-1 ${open || s.instructions ? 'text-blue-600' : 'text-gray-300 hover:text-gray-500'}`}>≡</button>
                {!nested && onAddSub && <button onClick={onAddSub} title="add subtask" className="px-1 text-gray-400 hover:text-blue-600">↳+</button>}
                <button onClick={onRemove} title="remove" className="px-1 text-gray-300 hover:text-red-600">×</button>
            </div>
            {open && (
                <div className="border-t border-gray-100 dark:border-gray-800 p-2.5 space-y-1.5">
                    <input value={s.why || ''} placeholder="Why — one line. What breaks if this is skipped."
                        onChange={(e) => onChange((x) => ({ ...x, why: e.target.value }))}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs focus:border-blue-500 focus:ring-blue-500" />
                    <textarea rows={3} value={s.instructions} placeholder="How — your SOP's wording, verbatim. Paths, commands, GPO names."
                        onChange={(e) => onChange((x) => ({ ...x, instructions: e.target.value }))}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs focus:border-blue-500 focus:ring-blue-500" />
                    <input value={s.done_when || ''} placeholder="Done when — something OBSERVABLE. e.g. recovery key visible in AD."
                        onChange={(e) => onChange((x) => ({ ...x, done_when: e.target.value }))}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs focus:border-blue-500 focus:ring-blue-500" />
                    <input value={s.record || ''} placeholder="Record — what enters the registry/inventory. e.g. key escrowed against asset tag."
                        onChange={(e) => onChange((x) => ({ ...x, record: e.target.value }))}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-xs focus:border-blue-500 focus:ring-blue-500" />
                    <label className="mt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <input type="checkbox" checked={!!s.automatable} onChange={(e) => onChange((x) => ({ ...x, automatable: e.target.checked }))}
                            className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                        Automatable later (API/script) — stays a manual task until wired
                    </label>
                </div>
            )}
            {renderSub && (s.subtasks || []).length > 0 && (
                <ol className="space-y-1 px-2.5 pb-2.5">{s.subtasks.map((sub, j) => renderSub(sub, j))}</ol>
            )}
        </li>
    );
}

/**
 * The wizard. Fill in the person, add vendors to the list (autofilled from the
 * vendors you already have — each becomes a created credential in the registry
 * plus a task), pick floating accounts to assign, hit run. Atomic server-side:
 * abandonment leaves nothing behind.
 */
function RunCard() {
    const empty = { first: '', last: '', username: '', email: '', title: '', department: '', start_date: '' };
    const [form, setForm] = useState(empty);
    const [vendorIds, setVendorIds] = useState([]);
    const [accountIds, setAccountIds] = useState([]);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    const run = async () => {
        setBusy(true); setError(null);
        try {
            const r = await api('/data/onboarding-run', 'POST', { ...form, vendor_ids: vendorIds, account_ids: accountIds });
            setResult(r); setForm(empty); setVendorIds([]); setAccountIds([]);
        } catch (e) {
            setError(Object.values(e?.errors || {}).flat()[0] || e?.message || 'Could not run onboarding.');
        }
        setBusy(false);
    };

    if (result) {
        return (
            <div className="rounded-lg border border-green-200 dark:border-green-900 bg-green-50 dark:bg-green-500/10 p-4">
                <p className="text-sm font-medium text-green-800 dark:text-green-300 mb-1">Onboarding created</p>
                <ul className="text-sm text-green-700 dark:text-green-400 space-y-0.5">
                    <li>Person added to the directory (sign-in stays off until granted).</li>
                    <li>{result.credentials.length} credential{result.credentials.length === 1 ? '' : 's'} generated and stored in the registry{result.credentials.length ? `: ${result.credentials.map((c) => c.vendor + (c.provisioned === true ? ' (auto-created via API)' : '')).join(', ')}` : ''}.</li>
                    {result.floating.length > 0 && <li>Floating accounts assigned: {result.floating.join(', ')}.</li>}
                    <li>{result.tasks} tasks created under the project — chained and planned around the start date.</li>
                </ul>
                <div className="mt-3 flex gap-2">
                    <a href="/tasks" className="px-3 py-1.5 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700">Open the task project</a>
                    <button onClick={() => setResult(null)} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">Onboard another</button>
                </div>
            </div>
        );
    }

    return (
        <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
            <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100 mb-3">Start an onboarding</h2>
            <div className="grid grid-cols-2 gap-3 mb-3">
                <Fld label="First name *"><input value={form.first} onChange={set('first')} className={INP} /></Fld>
                <Fld label="Last name *"><input value={form.last} onChange={set('last')} className={INP} /></Fld>
                <Fld label="Email *"><input type="email" value={form.email} onChange={set('email')} className={INP} /></Fld>
                <Fld label="Username (for Domain/AD)"><input value={form.username} onChange={set('username')} className={INP} /></Fld>
                <Fld label="Title"><input value={form.title} onChange={set('title')} className={INP} /></Fld>
                <Fld label="Department"><input value={form.department} onChange={set('department')} className={INP} /></Fld>
                <Fld label="Start date (DOH) *"><input type="date" value={form.start_date} onChange={set('start_date')} className={INP} /></Fld>
            </div>
            <Fld label="Create accounts for these vendors — each becomes a registry credential + a task">
                <MultiPicker ids={vendorIds} endpoint="/data/vendor-options" onChange={setVendorIds} placeholder="Search vendors to add…" />
            </Fld>
            <div className="mt-3">
                <Fld label="Assign floating accounts (pooled seats, shared mailboxes)">
                    <MultiPicker ids={accountIds} endpoint="/data/account-options" onChange={setAccountIds} placeholder="Search floating accounts…" />
                </Fld>
            </div>
            {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
            <div className="mt-4">
                <AddButton label={busy ? 'Creating…' : 'Run onboarding'} onClick={busy ? () => {} : run} />
            </div>
        </div>
    );
}

const INP = 'w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500';
function Fld({ label, children }) {
    return (
        <label className="block">
            <span className="block text-xs uppercase tracking-wide text-gray-400 mb-1">{label}</span>
            {children}
        </label>
    );
}
