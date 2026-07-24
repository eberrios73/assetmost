import { useEffect, useRef, useState } from 'react';
import { DOC_TEMPLATES } from '@/docTemplates';
import { DocIcon, ClipboardIcon, WrenchIcon, AlertIcon } from '@/Components/Icons';

const TPL_ICON = { clipboard: ClipboardIcon, wrench: WrenchIcon, alert: AlertIcon, doc: DocIcon };
function TplIcon({ iconKey }) {
    const Ic = TPL_ICON[iconKey] || DocIcon;
    return <Ic className="h-4 w-4 text-gray-500 dark:text-gray-400" />;
}

/** Dropdown that picks a doc template. onPick(templateKey). */
export default function TemplateMenu({ label = 'New', glyph = null, onPick, compact = false, className = '' }) {
    const [open, setOpen] = useState(false);
    const [customs, setCustoms] = useState([]);
    const ref = useRef(null);
    // The company's own templates ride along in every menu — fetched on first open.
    useEffect(() => {
        if (!open || customs.length) return;
        fetch('/data/doc-templates', { headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then((d) => setCustoms(Array.isArray(d) ? d : [])).catch(() => {});
    }, [open]);
    useEffect(() => {
        const onDoc = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);
    return (
        <div className="relative" ref={ref}>
            <button onClick={() => setOpen((o) => !o)}
                className={className || `inline-flex items-center justify-center gap-1.5 rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600 ${compact ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm'}`}>
                {glyph}{label} <svg className="h-3.5 w-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.4"><path d="M6 9l6 6 6-6" strokeLinecap="round" strokeLinejoin="round"/></svg>
            </button>
            {open && (
                <div className="absolute right-0 z-20 mt-1 w-56 rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1">
                    <div className="px-3 py-1 text-[11px] uppercase tracking-wide text-gray-400">New page from…</div>
                    {DOC_TEMPLATES.map((tpl) => (
                        <button key={tpl.key} onClick={() => { setOpen(false); onPick(tpl.key); }}
                            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                            <TplIcon iconKey={tpl.iconKey} />
                            <span><span className="block">{tpl.label}</span><span className="block text-xs text-gray-400">{tpl.hint}</span></span>
                        </button>
                    ))}
                    {customs.map((c) => (
                        <button key={`custom-${c.id}`} onClick={() => { setOpen(false); onPick(`custom:${c.id}`, c); }}
                            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                            <DocIcon className="h-4 w-4 text-gray-500 dark:text-gray-400" />
                            <span><span className="block">{c.label}</span><span className="block text-xs text-gray-400">{c.hint || 'Company template'}</span></span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
