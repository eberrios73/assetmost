import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import AddButton from '@/Components/ui/AddButton';
import { MultiPicker } from '@/Components/RecordModal';
import SearchSelect from '@/Components/SearchSelect';
import DocEditor from '@/Components/DocEditor';

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
 * IS the instruction text, and the human rearranges.
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
 * The workflow editor — a filtered lens on ONE workflow doc. Tabbed:
 *   Run    — people wizards only: the onboarding form.
 *   SOP    — THE DOC ITSELF, in the same DocEditor as Docs (one renderer, one
 *            look, slash commands included); steps recompile from it on save.
 *            A slim toolbar carries the listed toggle, task preview, duplicate
 *            and the Docs link; import sources show here while it's empty.
 *   Script — device workflows: the bootstrap script this SOP produces.
 * The `workflow` summary comes from the left-column list (Workspace).
 */
export default function OnboardingSetup({ workflow, onChanged }) {
    const wfId = workflow.id;
    const { auth } = usePage().props;
    const me = [auth?.user?.name, auth?.user?.last].filter(Boolean).join(' ');
    const [wf, setWf] = useState(null);              // full detail
    const [steps, setSteps] = useState(null);
    const [bodyRev, setBodyRev] = useState(0);       // re-key the editor when body reloads
    const [preview, setPreview] = useState(null);    // 'load' | {rows:[...]}
    const [text, setText] = useState('');
    const [saved, setSaved] = useState('');
    const [tab, setTab] = useState('steps');         // run | steps | script

    const load = (resetTab = false) => {
        api(`/data/workflows/${wfId}`).then((r) => {
            setWf(r);
            setSteps(r.steps?.steps?.length ? r.steps.steps : null);
            setBodyRev((v) => v + 1);
            if (resetTab) setTab(r.wizard ? 'run' : 'steps');
        }).catch(() => {});
    };
    useEffect(() => { load(true); }, [wfId]);
    useEffect(() => {
        if (preview !== 'load') return;
        api(`/data/workflows/${wfId}/preview`).then(setPreview).catch(() => setPreview(null));
    }, [preview, wfId]);

    const save = async (next) => {
        setSteps(next);
        await api(`/data/workflows/${wfId}/steps`, 'PUT', { steps: { version: 1, steps: next } });
        setSaved('Saved'); setTimeout(() => setSaved(''), 1200);
    };
    // The SOP tab saves the doc body itself; the server recompiles the steps from it.
    const saveBody = async (html) => {
        const r = await api(`/data/docs/${wfId}`, 'PATCH', { body: html });
        setSaved('Saved'); setTimeout(() => setSaved(''), 1200);
        if (r?.recompiled && steps === null) load();
    };
    const adopt = async () => { await api(`/data/workflows/${wfId}/adopt`, 'POST'); load(); };
    const importDoc = async (pageId) => { if (!pageId) return; await api(`/data/workflows/${wfId}/parse-doc`, 'POST', { page_id: pageId }); load(); };
    const toggleActive = async () => { await api(`/data/workflows/${wfId}`, 'PATCH', { active: !wf.active }); load(); onChanged?.(); };
    const duplicate = async () => { await api(`/data/workflows/${wfId}/duplicate`, 'POST'); onChanged?.(); };

    // Only the tabs this workflow can use: people wizards run, devices script.
    const tabs = wf?.wizard ? [['run', 'Run'], ['steps', 'SOP']]
        : wf?.type === 'device' ? [['steps', 'SOP'], ['script', 'Script']]
        : [['steps', 'SOP']];
    // Same underline style as the sub-tabs, so the app has one tab language.
    const tabBar = (
        <div className="flex">
            {tabs.map(([k, label]) => (
                <button key={k} onClick={() => setTab(k)}
                    className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${tab === k ? 'text-blue-600 border-blue-600' : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'}`}>
                    {label}
                </button>
            ))}
        </div>
    );

    if (!wf) return <div className="max-w-3xl"><p className="text-sm text-gray-400 py-6">Loading…</p></div>;

    return (
        <div className="max-w-3xl">
            <div className="mb-4 flex items-end justify-between gap-3 border-b border-gray-200 dark:border-gray-800">
                {tabBar}
                {saved && <span className="pb-2 text-xs text-green-600">{saved}</span>}
            </div>

            {tab === 'run' && wf.wizard && <RunCard workflowId={wfId} />}

            {tab === 'steps' && (
                <div>
                    {/* What Info used to hold, as one slim row on the SOP itself. */}
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 cursor-pointer"
                            title="Off = hidden from the Onboarding lists and it can't run. The doc itself stays in Docs either way.">
                            <input type="checkbox" checked={!!wf.active} onChange={toggleActive}
                                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                            Listed in Onboarding
                        </label>
                        <div className="flex items-center gap-2">
                            {steps !== null && (
                                <button onClick={() => setPreview(preview ? null : 'load')}
                                    className="px-3 py-1.5 text-sm rounded-md border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-500/10">
                                    {preview ? 'Hide task preview' : 'Preview tasks'}
                                </button>
                            )}
                            <button onClick={duplicate}
                                className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                                title="Copy this SOP to make a variant (e.g. Other Device -> Access Point)">Duplicate</button>
                            <a href={`/docs?page=${wf.id}`} className="text-sm text-blue-600 dark:text-blue-400 hover:underline shrink-0" title="This workflow IS a Docs page">
                                Open in Docs
                            </a>
                        </div>
                    </div>
                    {preview === 'load' && <p className="mb-3 text-sm text-gray-400">Building preview…</p>}
                    {preview && preview !== 'load' && (
                        <div className="mb-3 rounded-lg border border-blue-200 dark:border-blue-900 bg-blue-50/50 dark:bg-blue-500/5 p-4">
                            <p className="text-sm font-medium text-blue-800 dark:text-blue-300 mb-2">
                                The checklist this becomes — {preview.rows.length} tasks. Steps pulled from a referenced runbook
                                (like <code>/eprotection</code>) are marked <span className="rounded bg-amber-100 dark:bg-amber-500/15 px-1 text-[10px] text-amber-700 dark:text-amber-400">linked</span> and stay current.
                            </p>
                            <ol className="space-y-0.5">
                                {preview.rows.map((r, i) => (
                                    <li key={i} className={`flex items-start gap-2 text-sm ${r.depth ? 'pl-6 text-gray-500 dark:text-gray-400' : 'text-gray-800 dark:text-gray-100 font-medium'}`}>
                                        <span className="text-gray-300 dark:text-gray-600">{r.depth ? '↳' : '•'}</span>
                                        <span>
                                            {r.title}
                                            {r.ref && <span className="ml-2 rounded bg-amber-100 dark:bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-normal text-amber-700 dark:text-amber-400">linked</span>}
                                            {r.form && <span className="ml-2 rounded bg-blue-100 dark:bg-blue-500/15 px-1.5 py-0.5 text-[10px] font-normal text-blue-700 dark:text-blue-400">form: {r.form}</span>}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    )}
                    {(steps === null && !wf.body) ? (
                        <ImportSources pasting={false} shipped={wf.shipped}
                            text={text} setText={setText}
                            onParse={async () => { const parsed = parseSop(text); if (parsed.length) { await save(parsed); setText(''); load(); } }}
                            onCancel={null}
                            onPickDoc={importDoc} onAdopt={adopt} />
                    ) : (
                    <div>
                        <p className="mb-3 text-sm text-gray-500 dark:text-gray-400">
                            This IS the doc — the same page, same editor as Docs. Type <code className="text-xs bg-gray-100 dark:bg-gray-800 rounded px-1">/</code> for
                            commands (<code className="text-xs">/install</code> <code className="text-xs">/vpn</code> <code className="text-xs">/mdm</code> <code className="text-xs">/form</code>);
                            steps and tasks compile from what you write. Placeholders
                            <code className="mx-1 text-xs bg-gray-100 dark:bg-gray-800 rounded px-1">{'{first} {last} {username} {email} {start_date} {local_domain} {domain}'}</code>
                            fill in at run time.
                        </p>
                        <DocEditor key={`${wf.id}:${bodyRev}`} pageId={wf.id} initialBody={wf.body} onSave={saveBody} ownerDefault={me} companyId={wf.company_id}
                            osDefault={wf.sop_meta?.os || (/mac/i.test(wf.form_factor || '') ? 'macOS'
                                : /windows/i.test(wf.form_factor || '') ? 'Windows'
                                : /linux/i.test(wf.form_factor || '') ? 'Linux'
                                : /ios/i.test(wf.form_factor || '') ? 'iOS'
                                : /android/i.test(wf.form_factor || '') ? 'Android' : '')} />
                    </div>
                    )}
                </div>
            )}

            {tab === 'script' && <ScriptPanel wf={wf} />}
        </div>
    );
}

/** The adopt / import-from-doc / paste sources (when there are no steps yet). */
function ImportSources({ pasting, shipped, text, setText, onParse, onCancel, onPickDoc, onAdopt }) {
    return (
        <div className="space-y-3">
            {!pasting && (
                <>
                    <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                        <p className="text-sm font-medium text-gray-800 dark:text-gray-100 mb-1">Import from a Docs page</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                            Parse an existing doc into this workflow's steps — a one-time import; the steps are yours after.
                        </p>
                        <SearchSelect value={null} endpoint="/data/doc-options" placeholder="Search your Docs pages…" onChange={onPickDoc} />
                    </div>
                    {shipped && (
                        <div className="rounded-lg border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-500/10 p-4">
                            <p className="text-sm text-blue-800 dark:text-blue-300 mb-3">
                                Start from the shipped baseline — placeholder steps that show what this procedure covers; yours to edit.
                            </p>
                            <AddButton label="Use the standard SOP" onClick={onAdopt} />
                        </div>
                    )}
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

/**
 * The Script tab. People workflows build a task project, not a script. Device
 * workflows with a form factor produce the real bootstrap script from the SOP —
 * placeholders for device specifics, but /install, /vpn and /mdm resolve for real.
 */
function ScriptPanel({ wf }) {
    const [script, setScript] = useState('');
    const [loading, setLoading] = useState(false);
    const [copied, setCopied] = useState(false);
    const scriptable = wf.type === 'device' && wf.form_factor;
    // One SOP, one OS: the header's OS row decides the platform.
    const os = wf.sop_meta?.os || '';

    const gen = () => {
        setLoading(true);
        api(`/data/onboarding-script?workflow=${wf.id}`)
            .then((r) => setScript(r.script || ''))
            .catch((e) => setScript(`# ${Object.values(e?.errors || {}).flat()[0] || e?.message || 'Could not generate a script for this runbook.'}`))
            .finally(() => setLoading(false));
    };
    useEffect(() => { if (scriptable) gen(); else setScript(''); }, [wf.id]);

    if (!wf.form_factor) {
        return <p className="text-sm text-gray-500 dark:text-gray-400">This runbook is pulled into others with <code>/{wf.slug || 'ref'}</code> and doesn't produce a standalone machine script.</p>;
    }

    return (
        <div className="space-y-2">
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {os
                        ? <span className="mr-1 rounded bg-blue-100 dark:bg-blue-500/15 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:text-blue-400">OS: {os}</span>
                        : <span className="mr-1 rounded bg-amber-100 dark:bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400">No OS in the SOP header — using the form factor</span>}
                    Generated from this workflow's steps — <code>{'{ASSET_TAG}'}</code> and friends fill in per machine; the SOP's
                    <code className="mx-1">/install</code><code className="mr-1">/vpn</code><code>/mdm</code> resolve for real.
                </p>
                <div className="flex gap-2 shrink-0">
                    <button onClick={() => gen()} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Regenerate</button>
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

/**
 * The run wizard for a people workflow. Fill in the person, add vendors (each
 * becomes a created credential in the registry plus a task), pick floating
 * accounts, hit run. Atomic server-side: abandonment leaves nothing behind.
 */
function RunCard({ workflowId }) {
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
            const r = await api(`/data/workflows/${workflowId}/run`, 'POST', { ...form, vendor_ids: vendorIds, account_ids: accountIds });
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
