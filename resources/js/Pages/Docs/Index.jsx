import { PlusIcon } from "@/Components/Icons";
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import DocEditor from '@/Components/DocEditor';
import TemplateMenu from '@/Components/TemplateMenu';
import { buildDocBody, templateIcon, templateCategory, DOC_CATEGORIES, CATEGORY_STYLE } from '@/docTemplates';
import { getLast, setLast } from '@/lib/lastView';

const NEW_TITLES = { sop: 'New SOP', troubleshooting: 'New troubleshooting guide', incident: 'New incident report', freeform: 'Untitled' };

const COLLAPSE_KEY = 'assetmost:docs:collapsed';
const readCollapsed = () => { try { return JSON.parse(localStorage.getItem(COLLAPSE_KEY)) || []; } catch { return []; } };
const folderIds = (nodes, acc = []) => { (nodes || []).forEach((n) => { if (n.children?.length) { acc.push(n.id); folderIds(n.children, acc); } }); return acc; };

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const api = (url, method = 'GET', body) => fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    body: body ? JSON.stringify(body) : undefined,
}).then((r) => (r.status === 204 ? {} : r.json()));

export default function Index() {
    const { tenant } = usePage().props;
    const scope = `sel:docs:${tenant?.activeId ?? 'all'}`;
    const [tree, setTree] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [page, setPage] = useState(null);
    const [status, setStatus] = useState('');
    const [filter, setFilter] = useState('');   // category filter; '' = all (tree view)
    const [collapsed, setCollapsed] = useState(() => new Set(readCollapsed()));
    const collapseInit = useRef(localStorage.getItem(COLLAPSE_KEY) !== null);
    const titleTimer = useRef(null);

    const persistCollapsed = (set) => { try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify([...set])); } catch { /* ignore */ } };
    const toggleCollapse = (id) => setCollapsed((prev) => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); persistCollapsed(n); return n; });
    const setAllCollapsed = (on) => { const s = on ? new Set(folderIds(tree)) : new Set(); setCollapsed(s); persistCollapsed(s); };

    const openPage = (id) => { setSelectedId(id); setLast(scope, id); };
    const loadTree = () => api('/data/docs').then(setTree);
    useEffect(() => { loadTree(); }, []);
    // first visit (no saved state): start with folders collapsed so you land on the top level
    useEffect(() => {
        if (!collapseInit.current && tree.length) {
            const s = new Set(folderIds(tree));
            setCollapsed(s); persistCollapsed(s); collapseInit.current = true;
        }
    }, [tree]);
    // open ?page=ID (deep-link, e.g. after "Make doc"); otherwise restore the last page viewed
    useEffect(() => {
        const p = new URLSearchParams(window.location.search).get('page');
        setSelectedId(p ? Number(p) : getLast(scope));
    }, [scope]);
    useEffect(() => {
        if (!selectedId) { setPage(null); return; }
        api(`/data/docs/${selectedId}`)
            .then((p) => { if (p && p.id) setPage(p); else { setSelectedId(null); setLast(scope, null); } });
    }, [selectedId]);

    const newPage = async (parentId = null, templateKey = 'freeform') => {
        const { id } = await api('/data/docs', 'POST', {
            parent_id: parentId, title: NEW_TITLES[templateKey] || 'Untitled',
            body: buildDocBody(templateKey), icon: templateIcon(templateKey), category: templateCategory(templateKey),
        });
        await loadTree();
        openPage(id);
    };
    const saveCategory = async (category) => {
        setPage((p) => ({ ...p, category }));
        await api(`/data/docs/${selectedId}`, 'PATCH', { category: category || null });
        loadTree();
    };
    const saveBody = async (html) => {
        setStatus('Saving…');
        await api(`/data/docs/${selectedId}`, 'PATCH', { body: html });
        setStatus('Saved');
        setTimeout(() => setStatus(''), 1200);
    };
    const saveTitle = (title) => {
        setPage((p) => ({ ...p, title }));
        clearTimeout(titleTimer.current);
        titleTimer.current = setTimeout(async () => { await api(`/data/docs/${selectedId}`, 'PATCH', { title: title || 'Untitled' }); loadTree(); }, 500);
    };
    const del = async () => {
        if (!confirm('Delete this page?')) return;
        await api(`/data/docs/${selectedId}`, 'DELETE');
        openPage(null); loadTree();
    };

    const flat = flatten(tree).filter((n) => n.category === filter);

    const nav = (
        <div className="flex flex-col h-full">
            <div className="p-3 border-b border-gray-100 flex items-center justify-between">
                <span className="text-sm font-semibold text-gray-700 dark:text-gray-200">Pages</span>
                <TemplateMenu label="New" glyph={<PlusIcon />} onPick={(k) => newPage(null, k)}
                    className="text-xs rounded-md bg-blue-600 text-white px-2 py-1 hover:bg-blue-700 inline-flex items-center gap-1" />
            </div>
            <div className="px-3 py-2 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2">
                <select value={filter} onChange={(e) => setFilter(e.target.value)}
                    className="flex-1 rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500">
                    <option value="">All pages</option>
                    {DOC_CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
                {!filter && (
                    <button onClick={() => setAllCollapsed(collapsed.size === 0)}
                        title={collapsed.size === 0 ? 'Collapse all' : 'Expand all'}
                        className="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-sm px-1">
                        {collapsed.size === 0 ? '⊟' : '⊞'}
                    </button>
                )}
            </div>
            <div className="flex-1 overflow-y-auto py-1">
                {filter ? (
                    flat.length ? flat.map((n) => (
                        <button key={n.id} onClick={() => openPage(n.id)}
                            className={`group flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-blue-50/60 dark:hover:bg-gray-800 ${selectedId === n.id ? 'bg-blue-50 dark:bg-blue-500/10' : ''}`}>
                            <span>{n.icon || '📄'}</span>
                            <span className="flex-1 truncate text-gray-700 dark:text-gray-300">{n.title}</span>
                            <CategoryBadge category={n.category} />
                        </button>
                    )) : <div className="p-4 text-sm text-gray-400">No {filter} pages.</div>
                ) : (
                    tree.length ? <Tree nodes={tree} depth={0} selectedId={selectedId} onSelect={openPage} onAddChild={newPage} collapsed={collapsed} onToggle={toggleCollapse} />
                        : <div className="p-4 text-sm text-gray-400">No pages yet. Create one.</div>
                )}
            </div>
        </div>
    );

    const detail = page ? (
        <div className="max-w-3xl mx-auto">
            <div className="flex items-start justify-between mb-2 gap-4">
                <input value={page.title} onChange={(e) => saveTitle(e.target.value)}
                    className="flex-1 text-3xl font-bold text-gray-900 dark:text-white border-0 focus:ring-0 px-0 placeholder-gray-300" placeholder="Untitled" />
                <div className="mt-2 shrink-0 flex items-center gap-2">
                    <select value={page.category || ''} onChange={(e) => saveCategory(e.target.value)} title="Category"
                        className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm py-1.5 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Uncategorized</option>
                        {DOC_CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                    <button onClick={del} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600">Delete</button>
                </div>
            </div>
            <DocEditor key={page.id} pageId={page.id} initialBody={page.body} onSave={saveBody} />
        </div>
    ) : (
        <div className="h-full flex items-center justify-center text-gray-400 text-sm">Select or create a page</div>
    );

    return (
        <>
            <Head title="Docs" />
            <AppShell active="docs" nav={nav} detail={detail}
                footer={<div className="flex w-full justify-between"><span>Docs — {countPages(tree)} pages</span><span className="text-gray-400">{status}</span></div>} />
        </>
    );
}

function Tree({ nodes, depth, selectedId, onSelect, onAddChild, collapsed, onToggle }) {
    return (
        <ul>
            {nodes.map((n) => {
                const hasKids = n.children?.length > 0;
                const isCollapsed = collapsed.has(n.id);
                return (
                    <li key={n.id}>
                        <div className={`group flex items-center pr-2 hover:bg-blue-50/60 dark:hover:bg-gray-800 ${selectedId === n.id ? 'bg-blue-50 dark:bg-blue-500/10' : ''}`} style={{ paddingLeft: `${6 + depth * 14}px` }}>
                            {hasKids ? (
                                <button onClick={() => onToggle(n.id)} title={isCollapsed ? 'Expand' : 'Collapse'}
                                    className="w-4 shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">{isCollapsed ? '▸' : '▾'}</button>
                            ) : <span className="w-4 shrink-0" />}
                            <button onClick={() => onSelect(n.id)} className="flex-1 min-w-0 text-left py-1.5 text-sm text-gray-700 dark:text-gray-300 truncate">
                                <span className="mr-1">{n.icon || '📄'}</span>{n.title}
                            </button>
                            <CategoryBadge category={n.category} />
                            <button onClick={() => onAddChild(n.id)} title="Add sub-page" className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-blue-600 px-1"><PlusIcon /></button>
                        </div>
                        {hasKids && !isCollapsed && <Tree nodes={n.children} depth={depth + 1} selectedId={selectedId} onSelect={onSelect} onAddChild={onAddChild} collapsed={collapsed} onToggle={onToggle} />}
                    </li>
                );
            })}
        </ul>
    );
}
function countPages(nodes) { return (nodes || []).reduce((s, n) => s + 1 + countPages(n.children), 0); }
function flatten(nodes) { return (nodes || []).flatMap((n) => [{ id: n.id, title: n.title, icon: n.icon, category: n.category }, ...flatten(n.children)]); }

function CategoryBadge({ category }) {
    if (!category) return null;
    return <span className={`shrink-0 text-[10px] px-1.5 py-0.5 rounded ${CATEGORY_STYLE[category] || CATEGORY_STYLE.Reference}`}>{category}</span>;
}
