import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { setLast } from '@/lib/lastView';

/**
 * The power bar: ⌘. (or ⌘K / Ctrl+.) from anywhere. The grammar is the product:
 * "/" acts, "@" targets — `/rdp @501`, `/info @maya`, `/task order toner`.
 * No verb = find and go. Targets can be ambiguous on purpose; asset tags are
 * unique enough that `@501` resolves without saying "device".
 *
 * Tier 0/1 only: pure-data answers (/info) and handoffs to clients the machine
 * already has (/rdp /ssh /vnc /web). Nothing here executes remotely.
 */

const VERBS = {
    rdp:  { hint: 'Remote Desktop', target: 'machine' },
    ssh:  { hint: 'SSH', target: 'machine' },
    vnc:  { hint: 'Screen share', target: 'machine' },
    web:  { hint: "Device's web UI", target: 'machine' },
    info: { hint: 'Quick facts', target: 'any' },
    task: { hint: 'New task, stay put', target: 'none' },
    help: { hint: 'This cheatsheet', target: 'none' },
};

const HELP = [
    ['/rdp @501', 'Remote Desktop — downloads a pre-filled .rdp'],
    ['/ssh @501', 'opens Terminal on the machine'],
    ['/vnc @501', 'screen sharing'],
    ['/web @501', "the device's own web UI"],
    ['/info @maya', 'quick facts — person or machine'],
    ['/task order toner', 'new task without leaving'],
    ['/wifi Guest pw @501', 'any registry command, rendered for that machine'],
    ['plain text', 'find anything, Enter jumps to it'],
];

// `/wifi Guest secret @501` → verb wifi, args "Guest secret", target "501".
// Args and target are separate on purpose: args map onto the command's params,
// the @target supplies the machine context.
const parse = (q) => {
    const m = q.match(/^\/([\w-]+)\s*(.*)$/);
    if (!m) return { verb: null, args: '', rest: q.trim() };
    const at = m[2].match(/@(\S+)/);
    return {
        verb: m[1].toLowerCase(),
        args: m[2].replace(/@\S+/g, '').trim(),
        rest: at ? at[1] : '',
    };
};

const TYPE_GLYPH = { device: '🖥', person: '👤', doc: '📄', task: '☑' };

export default function PowerBar() {
    const { tenant } = usePage().props;
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [idx, setIdx] = useState(0);
    const [note, setNote] = useState(null);   // receipt / info card / error
    const inputRef = useRef(null);
    const timer = useRef(null);

    useEffect(() => {
        const onKey = (e) => {
            if ((e.metaKey || e.ctrlKey) && (e.key === '.' || e.key === 'k')) {
                e.preventDefault();
                setOpen((o) => !o); setQ(''); setResults([]); setNote(null); setIdx(0);
            } else if (e.key === 'Escape') setOpen(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => { if (open) setTimeout(() => inputRef.current?.focus(), 30); }, [open]);

    const { verb, args, rest } = parse(q);

    useEffect(() => {
        clearTimeout(timer.current);
        if (!open) return;
        const term = verb ? rest : q.trim();
        if (verb === 'task' || term.length < 2) { setResults([]); setIdx(0); return; }
        timer.current = setTimeout(() => {
            fetch(`/data/palette-search?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => { setResults(d.results || []); setIdx(0); });
        }, 150);
    }, [q, open]);

    if (!open) return null;

    const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');

    const navigateTo = (r) => {
        setOpen(false);
        // Same lastView keys the Workspace uses to restore tab + selection.
        const co = tenant?.activeId ?? 'all';
        if (r.type === 'device') { setLast('tab:assets', 'devices'); setLast(`sel:assets:devices:${co}`, r.id); router.visit('/assets'); }
        if (r.type === 'person') { setLast('tab:people', 'staff'); setLast(`sel:people:staff:${co}`, r.id); router.visit('/people'); }
        if (r.type === 'doc') router.visit(`/docs?page=${r.id}`);
        if (r.type === 'task') router.visit('/tasks');
    };

    const run = async (r) => {
        if (verb === 'help') return setNote({ kind: 'help' });
        // /task needs no target — the whole rest is the title.
        if (verb === 'task') {
            const title = args || rest || q.replace(/^\/task\s*/i, '').trim();
            if (!title) return setNote({ kind: 'err', text: 'Give the task a title: /task order toner' });
            const res = await fetch('/data/tasks', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({ title }),
            });
            return setNote(res.ok ? { kind: 'ok', text: `task: ${title}` } : { kind: 'err', text: 'Could not create the task.' });
        }
        if (!r) return;
        if (!verb) return navigateTo(r);

        // Not a built-in? Then it's a registry command — the same engine that
        // compiles SOP bootstrap scripts renders it for this @target.
        if (!VERBS[verb]) {
            if (r.type !== 'device') return setNote({ kind: 'err', text: `/${verb} needs an @device target.` });
            const res = await fetch(`/data/palette-render?command=${encodeURIComponent(verb)}&args=${encodeURIComponent(args)}&device_id=${r.id}`,
                { headers: { Accept: 'application/json' } });
            if (!res.ok) return setNote({ kind: 'err', text: `No /${verb} in the commands registry.` });
            const d = await res.json();
            const platform = ['mac', 'windows', 'linux'].find((p) => d[p]);
            return setNote(platform
                ? { kind: 'script', label: d.label || `/${verb}`, platform, text: d[platform], target: r.label }
                : { kind: 'err', text: `/${verb} has no script bodies yet.` });
        }

        const needsMachine = VERBS[verb]?.target === 'machine';
        const host = r.fqdn || r.ip;
        if (needsMachine && !host) {
            return setNote({ kind: 'err', text: `No address for ${r.label} — needs an asset tag + company local domain, or an IP.` });
        }
        switch (verb) {
            case 'info':
                return setNote({ kind: 'card', r });
            case 'rdp': {
                // A pre-filled .rdp: mstsc / Remote Desktop opens it and asks for
                // credentials itself — the app never touches them.
                const blob = new Blob([`full address:s:${host}\nprompt for credentials:i:1\n`], { type: 'application/x-rdp' });
                const a = Object.assign(document.createElement('a'), {
                    href: URL.createObjectURL(blob), download: `${r.device_label || r.label}.rdp`,
                });
                a.click(); URL.revokeObjectURL(a.href);
                return setNote({ kind: 'ok', text: `rdp → ${host}` });
            }
            case 'ssh': window.location.href = `ssh://${host}`; return setNote({ kind: 'ok', text: `ssh → ${host}` });
            case 'vnc': window.location.href = `vnc://${host}`; return setNote({ kind: 'ok', text: `vnc → ${host}` });
            case 'web': window.open(`http://${r.ip || host}`, '_blank'); return setNote({ kind: 'ok', text: `web → ${r.ip || host}` });
            default: return setNote({ kind: 'err', text: `Unknown command /${verb}` });
        }
    };

    const onKeyDown = (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setIdx((i) => Math.min(i + 1, results.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setIdx((i) => Math.max(i - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); run(results[idx]); }
    };

    return (
        <div className="fixed inset-0 z-[100] bg-black/25 backdrop-blur-[2px]" onMouseDown={() => setOpen(false)}>
            <div className="mx-auto mt-[16vh] w-[620px] max-w-[92vw] overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-2xl"
                onMouseDown={(e) => e.stopPropagation()}>
                <div className="flex items-center gap-2 border-b border-gray-100 dark:border-gray-800 px-4">
                    {verb && VERBS[verb] && (
                        <span className="rounded bg-blue-600 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-white">/{verb}</span>
                    )}
                    <input ref={inputRef} value={q} onChange={(e) => { setQ(e.target.value); setNote(null); }} onKeyDown={onKeyDown}
                        placeholder="Type /rdp @501, /task order toner, or just search…"
                        className="w-full border-0 bg-transparent py-3.5 text-[15px] text-gray-800 dark:text-gray-100 placeholder-gray-400 focus:ring-0" />
                    <kbd className="font-mono text-[10px] text-gray-300 dark:text-gray-600">esc</kbd>
                </div>

                {results.length > 0 && (
                    <ul className="max-h-72 overflow-y-auto py-1">
                        {results.map((r, i) => (
                            <li key={`${r.type}-${r.id}`}>
                                <button onClick={() => run(r)} onMouseEnter={() => setIdx(i)}
                                    className={`flex w-full items-center gap-3 px-4 py-2 text-left text-sm ${i === idx ? 'bg-blue-50 dark:bg-blue-500/15' : ''}`}>
                                    <span className="w-5 text-center">{TYPE_GLYPH[r.type]}</span>
                                    <span className="font-medium text-gray-800 dark:text-gray-100">{r.label}</span>
                                    <span className="truncate text-xs text-gray-400">{r.sub}</span>
                                    {verb && VERBS[verb]?.target === 'machine' && (
                                        <span className="ml-auto font-mono text-[11px] text-gray-400">{r.fqdn || r.ip || '—'}</span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {note?.kind === 'card' && (
                    <div className="border-t border-gray-100 dark:border-gray-800 px-4 py-3 text-sm">
                        <div className="font-semibold text-gray-800 dark:text-gray-100">{note.r.label}</div>
                        <div className="mt-1 grid grid-cols-2 gap-x-6 gap-y-0.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                            {note.r.sub && <span>{note.r.sub}</span>}
                            {note.r.fqdn && <span>{note.r.fqdn}</span>}
                            {note.r.ip && <span>{note.r.ip}</span>}
                            {note.r.company && <span>{note.r.company}</span>}
                            {note.r.device_label && <span>device: {note.r.device_label}</span>}
                        </div>
                    </div>
                )}
                {note?.kind === 'script' && (
                    <div className="border-t border-gray-100 dark:border-gray-800 px-4 py-3">
                        <div className="mb-1.5 flex items-center gap-2 text-xs">
                            <span className="font-semibold text-gray-700 dark:text-gray-200">{note.label}</span>
                            <span className="text-gray-400">{note.platform} · {note.target}</span>
                            <button onClick={() => navigator.clipboard.writeText(note.text)}
                                className="ml-auto rounded border border-gray-200 dark:border-gray-700 px-2 py-0.5 text-[11px] text-gray-500 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600">Copy</button>
                        </div>
                        <pre className="max-h-48 overflow-auto rounded-md bg-gray-900 p-3 font-mono text-[11.5px] leading-relaxed text-gray-100">{note.text}</pre>
                    </div>
                )}
                {note?.kind === 'help' && (
                    <div className="border-t border-gray-100 dark:border-gray-800 px-4 py-3 text-sm">
                        {HELP.map(([cmd, what]) => (
                            <div key={cmd} className="flex gap-3 py-0.5">
                                <code className="w-44 shrink-0 font-mono text-[12px] text-blue-600 dark:text-blue-400">{cmd}</code>
                                <span className="text-xs text-gray-500 dark:text-gray-400">{what}</span>
                            </div>
                        ))}
                        <div className="mt-1.5 text-[11px] text-gray-400">/ acts · @ targets — targets can be a tag, a number, or a name.</div>
                    </div>
                )}
                {(note?.kind === 'ok' || note?.kind === 'err') && (
                    <div className={`border-t border-gray-100 dark:border-gray-800 px-4 py-2.5 font-mono text-xs ${note.kind === 'ok' ? 'text-green-600 dark:text-green-400' : 'text-red-500'}`}>
                        {note.kind === 'ok' ? '✓ ' : '✗ '}{note.text}
                    </div>
                )}

                <div className="flex gap-4 border-t border-gray-100 dark:border-gray-800 px-4 py-2 font-mono text-[10.5px] text-gray-400">
                    <span><b className="text-gray-500 dark:text-gray-300">/</b> acts</span>
                    <span><b className="text-gray-500 dark:text-gray-300">@</b> targets</span>
                    <span>↵ runs</span>
                    <span className="ml-auto">/rdp /ssh /vnc /web /info /task · any registry /command</span>
                </div>
            </div>
        </div>
    );
}
