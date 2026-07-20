import { useMemo, useState } from 'react';
import AddButton from '@/Components/ui/AddButton';

/**
 * THE table. Every tabular screen renders this — one look, one behavior:
 *
 *  - column headers are the field names; click to sort (↑/↓), every column, always
 *  - `table-fixed` with explicit widths so sorting never re-measures columns
 *  - search box and filter dropdowns are always visible (a control that hides
 *    itself reads as a control that's missing)
 *  - "+ Add" is the shared AddButton, in the same corner on every screen
 *
 * columns: [{ key, label, width, render?(row), sortValue?(row), className? }]
 *   width is a CSS width ('30%', '8rem') — required for stable layout.
 * filters: [{ key, label, options: [..], value, onChange }] — plain selects.
 * rows are filtered client-side by `search` across every column's text.
 */
export default function DataTable({
    columns,
    rows,
    rowKey = (r) => r.id,
    onRowClick = null,
    filters = [],
    searchable = true,
    addLabel = null,
    onAdd = null,
    emptyText = 'Nothing here yet.',
    initialSort = null,            // { key, dir }
}) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState(initialSort || { key: null, dir: 'asc' });

    const text = (row, col) => {
        const v = col.sortValue ? col.sortValue(row) : row[col.key];
        return v === null || v === undefined ? '' : v;
    };

    const visible = useMemo(() => {
        let out = rows || [];
        if (search) {
            const q = search.toLowerCase();
            out = out.filter((r) => columns.some((c) => String(text(r, c)).toLowerCase().includes(q)));
        }
        if (sort.key) {
            const col = columns.find((c) => c.key === sort.key);
            out = [...out].sort((a, b) => {
                const A = text(a, col), B = text(b, col);
                const cmp = typeof A === 'number' && typeof B === 'number'
                    ? A - B
                    : String(A).localeCompare(String(B), undefined, { numeric: true });
                return sort.dir === 'asc' ? cmp : -cmp;
            });
        }
        return out;
    }, [rows, search, sort, columns]);

    const toggleSort = (key) =>
        setSort((s) => ({ key, dir: s.key === key && s.dir === 'asc' ? 'desc' : 'asc' }));

    return (
        <div>
            {/* Toolbar — same order everywhere: search, filters, count, add. */}
            <div className="flex items-center gap-2 mb-3">
                {searchable && (
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search…"
                        className="w-44 rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-500 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
                )}
                {filters.map((f) => (
                    <select key={f.key} value={f.value} onChange={(e) => f.onChange(e.target.value)}
                        className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 text-xs py-1.5 text-gray-600 dark:text-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">All {f.label}</option>
                        {f.options.map((o) => {
                            const value = typeof o === 'string' ? o : (o.value ?? o.id);
                            const label = typeof o === 'string' ? o : (o.label ?? o.name ?? value);
                            return <option key={value} value={value}>{label}</option>;
                        })}
                    </select>
                ))}
                <span className="text-xs text-gray-400">{visible.length} of {(rows || []).length}</span>
                {onAdd && <span className="ml-auto"><AddButton label={addLabel} onClick={onAdd} /></span>}
            </div>

            {!visible.length ? (
                <p className="text-sm text-gray-400 py-4">{emptyText}</p>
            ) : (
                <table className="w-full table-fixed text-sm">
                    <colgroup>
                        {columns.map((c) => <col key={c.key} style={{ width: c.width }} />)}
                    </colgroup>
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200 dark:border-gray-800">
                            {columns.map((c) => (
                                <th key={c.key} onClick={() => toggleSort(c.key)}
                                    className="py-2 pr-4 font-normal cursor-pointer select-none hover:text-gray-600 dark:hover:text-gray-200 truncate">
                                    {c.label}{sort.key === c.key ? (sort.dir === 'asc' ? ' ↑' : ' ↓') : ''}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((r) => (
                            <tr key={rowKey(r)} onClick={onRowClick ? () => onRowClick(r) : undefined}
                                className={`border-b border-gray-50 dark:border-gray-800 ${onRowClick ? 'cursor-pointer hover:bg-blue-50/40 dark:hover:bg-gray-800/50' : ''}`}>
                                {columns.map((c) => (
                                    <td key={c.key} className={`py-2 pr-4 truncate ${c.className || 'text-gray-500 dark:text-gray-400'}`}>
                                        {c.render ? c.render(r) : (r[c.key] ?? <span className="text-gray-300">—</span>)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}
