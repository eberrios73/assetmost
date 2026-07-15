import { useEffect, useRef, useState } from 'react';
import { DOC_TEMPLATES } from '@/docTemplates';

/** Dropdown that picks a doc template. onPick(templateKey). */
export default function TemplateMenu({ label = 'New', glyph = null, onPick, compact = false, className = '' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    useEffect(() => {
        const onDoc = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);
    return (
        <div className="relative" ref={ref}>
            <button onClick={() => setOpen((o) => !o)}
                className={className || `inline-flex items-center justify-center gap-1.5 rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600 ${compact ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm'}`}>
                {glyph}{label} <span className="text-gray-400">▾</span>
            </button>
            {open && (
                <div className="absolute right-0 z-20 mt-1 w-56 rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1">
                    <div className="px-3 py-1 text-[11px] uppercase tracking-wide text-gray-400">New page from…</div>
                    {DOC_TEMPLATES.map((tpl) => (
                        <button key={tpl.key} onClick={() => { setOpen(false); onPick(tpl.key); }}
                            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                            <span>{tpl.icon}</span>
                            <span><span className="block">{tpl.label}</span><span className="block text-xs text-gray-400">{tpl.hint}</span></span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
