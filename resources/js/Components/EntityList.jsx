import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Reusable infinite-scroll list panel: search + sort + optional filter + Active toggle.
 * Loads pages from `endpoint` (offset-based) and appends on scroll.
 * One component drives every entity screen — no per-page copies.
 */
export default function EntityList({
    endpoint,
    icon,
    filter = null,                 // { key, label, options: string[] }
    sortOptions = [],              // [{ key, label }]
    defaultSort = null,
    selectedId = null,
    onSelect,
    onStats = null,                // ({ shown, total }) => void
    reloadKey = 0,
}) {
    const [items, setItems] = useState([]);
    const [offset, setOffset] = useState(0);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState('');
    const [activeOnly, setActiveOnly] = useState(true);
    const [filterVal, setFilterVal] = useState('');
    const [sortKey, setSortKey] = useState(defaultSort || sortOptions[0]?.key || '');
    const [sortDir, setSortDir] = useState('asc');
    const sentinel = useRef(null);

    const load = useCallback(async (reset) => {
        setLoading(true);
        const off = reset ? 0 : offset;
        const params = new URLSearchParams({ offset: off, search, active_only: activeOnly ? 1 : 0 });
        if (filter && filterVal) params.set(filter.key, filterVal);
        if (sortKey) { params.set('sort', sortKey); params.set('dir', sortDir); }
        const res = await fetch(`${endpoint}?${params}`, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        setItems((prev) => {
            const next = reset ? data.items : [...prev, ...data.items];
            onStats?.({ shown: next.length, total: data.total });
            return next;
        });
        setOffset(data.next_offset);
        setHasMore(data.has_more);
        setLoading(false);
    }, [endpoint, offset, search, activeOnly, filterVal, filter, sortKey, sortDir, onStats]);

    useEffect(() => {
        const t = setTimeout(() => load(true), 200);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, activeOnly, filterVal, sortKey, sortDir, endpoint, reloadKey]);

    useEffect(() => {
        if (!sentinel.current) return;
        const io = new IntersectionObserver((e) => {
            if (e[0].isIntersecting && hasMore && !loading) load(false);
        }, { rootMargin: '200px' });
        io.observe(sentinel.current);
        return () => io.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [hasMore, loading, offset]);

    return (
        <div className="flex flex-col h-full">
            <div className="p-3 border-b border-gray-100 dark:border-gray-800 space-y-2">
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search…"
                    className="w-full rounded-lg border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-500 text-sm focus:border-blue-500 focus:ring-blue-500"
                />
                <div className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    {sortOptions.length > 0 && (
                        <div className="flex items-center">
                            <select
                                value={sortKey}
                                onChange={(e) => setSortKey(e.target.value)}
                                title="Sort by"
                                className="rounded-l-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 text-xs py-1 text-gray-600 dark:text-gray-300 focus:border-blue-500 focus:ring-blue-500"
                            >
                                {sortOptions.map((o) => <option key={o.key} value={o.key}>{o.label}</option>)}
                            </select>
                            <button
                                type="button"
                                onClick={() => setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))}
                                title={sortDir === 'asc' ? 'Ascending' : 'Descending'}
                                className="border border-l-0 border-gray-200 dark:border-gray-700 rounded-r-md px-2 py-1 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800"
                            >
                                {sortDir === 'asc' ? '↑' : '↓'}
                            </button>
                        </div>
                    )}
                    {filter && (
                        <select
                            value={filterVal}
                            onChange={(e) => setFilterVal(e.target.value)}
                            className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 text-xs py-1 text-gray-600 dark:text-gray-300 focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">All {filter.label}</option>
                            {filter.options.map((o) => <option key={o} value={o}>{o}</option>)}
                        </select>
                    )}
                    <label className="flex items-center gap-1.5 cursor-pointer ml-auto">
                        Active
                        <input type="checkbox" checked={activeOnly} onChange={(e) => setActiveOnly(e.target.checked)}
                            className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                    </label>
                </div>
            </div>

            <ul className="flex-1 overflow-y-auto">
                {items.map((it) => (
                    <li key={it.id}>
                        <button
                            onClick={() => onSelect(it.id)}
                            className={`w-full text-left flex items-center gap-3 px-3 py-2.5 border-b border-gray-50 dark:border-gray-800/70 hover:bg-blue-50/60 dark:hover:bg-blue-500/10 ${selectedId === it.id ? 'bg-blue-50 dark:bg-blue-500/15' : ''}`}
                        >
                            <span className="shrink-0 text-gray-400 dark:text-gray-500">{icon}</span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium text-gray-800 dark:text-gray-100 truncate">{it.primary}</span>
                                {it.secondary && <span className="block text-xs text-gray-500 dark:text-gray-400 truncate">{it.secondary}</span>}
                            </span>
                            {it.badge && <span className="text-xs font-medium text-blue-600 dark:text-blue-400 shrink-0">{it.badge}</span>}
                        </button>
                    </li>
                ))}
                <div ref={sentinel} />
                {loading && <li className="px-3 py-3 text-xs text-gray-400">Loading…</li>}
            </ul>
        </div>
    );
}
