import { useEffect, useRef, useState } from 'react';

/** Searchable dropdown backed by `endpoint` returning [{ id, label }]. */
export default function SearchSelect({ value, onChange, endpoint, placeholder = 'Search…' }) {
    const [options, setOptions] = useState([]);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const boxRef = useRef(null);

    useEffect(() => {
        fetch(endpoint, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setOptions);
    }, [endpoint]);

    useEffect(() => {
        const onDoc = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);

    const selected = options.find((o) => String(o.id) === String(value));
    const filtered = options.filter((o) => o.label.toLowerCase().includes(query.toLowerCase())).slice(0, 50);

    return (
        <div className="relative" ref={boxRef}>
            <div className="flex items-center">
                <input
                    value={open ? query : (selected?.label ?? '')}
                    onFocus={() => { setOpen(true); setQuery(''); }}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                    placeholder={placeholder}
                    className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500 pr-7"
                />
                {value && (
                    <button type="button" onClick={() => { onChange(null); setQuery(''); }}
                        className="absolute right-2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">×</button>
                )}
            </div>
            {open && (
                <div className="absolute z-10 mt-1 w-full max-h-56 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1">
                    {filtered.length === 0 && <div className="px-3 py-1.5 text-xs text-gray-400">No matches</div>}
                    {filtered.map((o) => (
                        <button key={o.id} type="button" onMouseDown={(e) => { e.preventDefault(); onChange(o.id); setOpen(false); }}
                            className={`w-full text-left px-3 py-1.5 text-sm ${String(o.id) === String(value) ? 'bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'}`}>
                            {o.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
