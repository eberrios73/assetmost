import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/** Searchable dropdown backed by `endpoint` returning [{ id, label }], or by a
 *  pre-loaded `options` array (skips the fetch — use when the parent already has
 *  the list, e.g. a table where every row shares one options set).
 *  `fallbackLabel` shows the current selection's name when it isn't in the
 *  options (e.g. an inactive user who is still the assignee).
 *  `portal` renders the menu in a fixed-position layer on <body> so it isn't
 *  clipped by an overflow container (e.g. a horizontally-scrolling table). */
const DEFAULT_INPUT = 'w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500 pr-7';

export default function SearchSelect({ value, onChange, endpoint, options: providedOptions, placeholder = 'Search…', fallbackLabel = '', portal = false, className = '', inputClassName = DEFAULT_INPUT }) {
    const [options, setOptions] = useState(providedOptions ?? []);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [rect, setRect] = useState(null);
    const boxRef = useRef(null);
    const inputRef = useRef(null);
    const menuRef = useRef(null);

    useEffect(() => {
        if (providedOptions) { setOptions(providedOptions); return; }
        if (!endpoint) return;
        fetch(endpoint, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setOptions);
    }, [endpoint, providedOptions]);

    useEffect(() => {
        const onDoc = (e) => {
            if (boxRef.current?.contains(e.target)) return;
            if (menuRef.current?.contains(e.target)) return;
            setOpen(false);
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);

    const openMenu = () => {
        setQuery('');
        if (portal && inputRef.current) setRect(inputRef.current.getBoundingClientRect());
        setOpen(true);
    };

    const selected = options.find((o) => String(o.id) === String(value));
    const displayLabel = selected?.label ?? (value ? fallbackLabel : '');
    const filtered = options.filter((o) => o.label.toLowerCase().includes(query.toLowerCase())).slice(0, 50);

    const items = (
        <>
            {filtered.length === 0 && <div className="px-3 py-1.5 text-xs text-gray-400">No matches</div>}
            {filtered.map((o) => (
                <button key={o.id} type="button" onMouseDown={(e) => { e.preventDefault(); onChange(o.id); setOpen(false); }}
                    className={`w-full text-left px-3 py-1.5 text-sm ${String(o.id) === String(value) ? 'bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'}`}>
                    {o.label}
                </button>
            ))}
        </>
    );

    return (
        <div className={`relative ${className}`} ref={boxRef}>
            <div className="flex items-center">
                <input
                    ref={inputRef}
                    value={open ? query : displayLabel}
                    onFocus={openMenu}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                    placeholder={placeholder}
                    className={inputClassName}
                />
                {value && (
                    <button type="button" onMouseDown={(e) => { e.preventDefault(); onChange(null); setQuery(''); }}
                        className="absolute right-2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">×</button>
                )}
            </div>
            {open && !portal && (
                <div ref={menuRef} className="absolute z-10 mt-1 w-full max-h-56 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1">
                    {items}
                </div>
            )}
            {open && portal && rect && createPortal(
                <div ref={menuRef} style={{ position: 'fixed', top: rect.bottom + 4, left: rect.left, width: Math.max(rect.width, 200), zIndex: 60 }}
                    className="max-h-56 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1">
                    {items}
                </div>,
                document.body,
            )}
        </div>
    );
}
