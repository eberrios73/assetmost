import { PlusIcon, DocIcon, Chevron } from "@/Components/Icons";
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import DocEditor from '@/Components/DocEditor';
import OnboardingSetup from '@/Components/OnboardingSetup';
import TemplateMenu from '@/Components/TemplateMenu';
import { buildDocBody, templateCategory, DOC_TEMPLATES, DOC_CATEGORIES, CATEGORY_STYLE } from '@/docTemplates';
import { getLast, setLast } from '@/lib/lastView';

const NEW_TITLES = { sop: 'New SOP', troubleshooting: 'New troubleshooting guide', incident: 'New incident report', freeform: 'Untitled' };

const SPACE_COLORS = ['#7c3aed', '#2563eb', '#b91c1c', '#0d9488', '#b45309', '#16a34a', '#db2777'];
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
    const activeId = tenant?.activeId ?? 'all';
    const scope = `sel:docs:${activeId}`;
    const spaceScope = `docs:space:${activeId}`;
    const [spaces, setSpaces] = useState([]);
    const [spaceId, setSpaceId] = useState(() => getLast(spaceScope));
    const [tree, setTree] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [page, setPage] = useState(null);
    const [status, setStatus] = useState('');
    const [filter, setFilter] = useState('');   // category filter; '' = all (tree view)
    const [navTab, setNavTab] = useState('docs');   // docs | templates
    const [searchQ, setSearchQ] = useState('');
    const [searchResults, setSearchResults] = useState(null);
    const searchTimer = useRef(null);
    const [collapsed, setCollapsed] = useState(() => new Set(readCollapsed()));
    const collapseInit = useRef(localStorage.getItem(COLLAPSE_KEY) !== null);
    const titleTimer = useRef(null);

    // Compare as strings: the remembered id comes back from localStorage/JSON and a
    // type mismatch here silently resets you to the first space on every load.
    const sameId = (a, b) => a != null && b != null && String(a) === String(b);
    const space = spaces.find((s) => sameId(s.id, spaceId)) || null;
    const persistCollapsed = (set) => { try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify([...set])); } catch { /* ignore */ } };
    const toggleCollapse = (id) => setCollapsed((prev) => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); persistCollapsed(n); return n; });
    const setAllCollapsed = (on) => { const s = on ? new Set(folderIds(tree)) : new Set(); setCollapsed(s); persistCollapsed(s); };

    const openPage = (id) => { setSelectedId(id); setLast(scope, id); };
    const chooseSpace = (id) => { setSpaceId(id); setLast(spaceScope, id); setSelectedId(null); collapseInit.current = false; };
    const loadSpaces = () => api('/data/spaces').then(setSpaces);
    const loadTree = () => api(`/data/docs${spaceId ? `?space=${spaceId}` : ''}`).then(setTree);

    useEffect(() => { loadSpaces(); }, []);
    // default to the first space once spaces load (if none remembered / stale)
    useEffect(() => {
        if (spaces.length && !spaces.some((s) => sameId(s.id, spaceId))) chooseSpace(spaces[0].id);
    }, [spaces]);
    useEffect(() => { if (spaceId) loadTree(); }, [spaceId]);
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
            parent_id: parentId, space_id: spaceId, title: NEW_TITLES[templateKey] || 'Untitled',
            body: buildDocBody(templateKey), category: templateCategory(templateKey),
        });
        await loadTree();
        openPage(id);
    };
    const newSpace = async () => {
        const name = prompt('New space name');
        if (!name?.trim()) return;
        const { id } = await api('/data/spaces', 'POST', { name: name.trim(), color: SPACE_COLORS[spaces.length % SPACE_COLORS.length] });
        await loadSpaces();
        chooseSpace(id);
    };
    // Drag a page onto another to nest it; drop on the tree background for top level.
    const moveDoc = async (id, parentId) => {
        if (id === parentId) return;
        await api(`/data/docs/${id}`, 'PATCH', { parent_id: parentId });
        if (page?.id === id) setPage((p) => ({ ...p, parent_id: parentId }));
        loadTree();
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

    // A new version duplicates the doc (workflow pages bump their header Version and
    // reset Status to Draft); the original stands, you land on the copy.
    const newVersion = async () => {
        const { id } = await api(`/data/docs/${selectedId}/new-version`, 'POST');
        await loadTree();
        openPage(id);
    };

    // Search every page (title first, then body), debounced; results replace the tree.
    const runSearch = (q) => {
        setSearchQ(q);
        clearTimeout(searchTimer.current);
        if (!q.trim()) { setSearchResults(null); return; }
        searchTimer.current = setTimeout(() => {
            api(`/data/docs-search?q=${encodeURIComponent(q.trim())}`).then((r) => setSearchResults(Array.isArray(r) ? r : []));
        }, 250);
    };
    const openResult = (r) => {
        if (r.space_id && !sameId(r.space_id, spaceId)) { setSpaceId(r.space_id); setLast(spaceScope, r.space_id); }
        openPage(r.id);
    };

    const flat = flatten(tree).filter((n) => n.category === filter);

    const nav = (
        <div className="flex flex-col h-full">
            <div className="p-2 border-b border-gray-100 dark:border-gray-800">
                <SpaceSwitcher spaces={spaces} space={space} onPick={chooseSpace} onNew={newSpace} />
            </div>
            <div className="flex border-b border-gray-200 dark:border-gray-800 px-2 pt-1">
                {[['docs', 'Docs'], ['templates', 'Templates']].map(([k, label]) => (
                    <button key={k} onClick={() => setNavTab(k)}
                        className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${navTab === k ? 'text-blue-600 border-blue-600' : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'}`}>
                        {label}
                    </button>
                ))}
            </div>
            {navTab === 'templates' ? (
                <div className="flex-1 overflow-y-auto p-3 space-y-2">
                    <p className="text-xs text-gray-500 dark:text-gray-400">Every new page starts from one of these. Pick one to create a page in {space?.name || 'this space'}.</p>
                    {DOC_TEMPLATES.map((t) => (
                        <button key={t.key} onClick={() => { setNavTab('docs'); newPage(null, t.key); }}
                            className="w-full rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800">
                            <span className="flex items-center justify-between gap-2">
                                <span className="text-sm font-medium text-gray-800 dark:text-gray-100">{t.label}</span>
                                <CategoryBadge category={t.category} />
                            </span>
                            <span className="block mt-0.5 text-xs text-gray-500 dark:text-gray-400">{t.hint}</span>
                        </button>
                    ))}
                </div>
            ) : (
                <>
                    <div className="px-3 py-2 flex items-center justify-between">
                        <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">Pages</span>
                        <TemplateMenu label="New" glyph={<PlusIcon />} onPick={(k) => newPage(selectedId ?? null, k)}
                            className="text-xs rounded-md bg-blue-600 text-white px-2 py-1 hover:bg-blue-700 inline-flex items-center gap-1" />
                    </div>
                    <div className="px-3 pb-2">
                        <input value={searchQ} onChange={(e) => runSearch(e.target.value)} placeholder="Search all docs…"
                            className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
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
                    <div className="flex-1 overflow-y-auto py-1"
                        onDragOver={(e) => e.preventDefault()}
                        onDrop={(e) => { const id = Number(e.dataTransfer.getData('doc-id')); if (id) moveDoc(id, null); }}>
                        {searchResults !== null ? (
                            searchResults.length ? searchResults.map((r) => (
                                <button key={r.id} onClick={() => openResult(r)}
                                    className={`group flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-blue-50/60 dark:hover:bg-gray-800 ${selectedId === r.id ? 'bg-blue-50 dark:bg-blue-500/10' : ''}`}>
                                    <DocIcon className="h-4 w-4 shrink-0 text-gray-400" />
                                    <span className="flex-1 truncate text-gray-700 dark:text-gray-300">{r.title}</span>
                                    <CategoryBadge category={r.category} />
                                </button>
                            )) : <div className="p-4 text-sm text-gray-400">Nothing matches "{searchQ}".</div>
                        ) : filter ? (
                            flat.length ? flat.map((n) => (
                                <button key={n.id} onClick={() => openPage(n.id)}
                                    className={`group flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-blue-50/60 dark:hover:bg-gray-800 ${selectedId === n.id ? 'bg-blue-50 dark:bg-blue-500/10' : ''}`}>
                                    <DocIcon className="h-4 w-4 shrink-0 text-gray-400" />
                                    <span className="flex-1 truncate text-gray-700 dark:text-gray-300">{n.title}</span>
                                    <CategoryBadge category={n.category} />
                                </button>
                            )) : <div className="p-4 text-sm text-gray-400">No {filter} pages.</div>
                        ) : (
                            tree.length ? <Tree nodes={tree} depth={0} selectedId={selectedId} onSelect={openPage} onAddChild={newPage} collapsed={collapsed} onToggle={toggleCollapse} onMove={moveDoc} />
                                : <div className="p-4 text-sm text-gray-400">No pages yet. Create one.</div>
                        )}
                    </div>
                </>
            )}
        </div>
    );

    const detail = page ? (
        <div className="max-w-3xl mx-auto">
            {page.current_id && (
                <div className="mb-3 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-500/10 px-4 py-2 text-sm text-amber-800 dark:text-amber-300 flex items-center justify-between gap-3">
                    <span>This is an old version — kept for the record.</span>
                    <button onClick={() => openPage(page.current_id)} className="shrink-0 text-blue-600 dark:text-blue-400 hover:underline">Open current</button>
                </div>
            )}
            <div className="flex items-start justify-between mb-2 gap-4">
                <input value={page.title} onChange={(e) => saveTitle(e.target.value)}
                    className="flex-1 text-3xl font-bold text-gray-900 dark:text-white border-0 focus:ring-0 px-0 placeholder-gray-300" placeholder="Untitled" />
                <div className="mt-2 shrink-0 flex items-center gap-2">

                    <select value={page.category || ''} onChange={(e) => saveCategory(e.target.value)} title="Category"
                        className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm py-1.5 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Uncategorized</option>
                        {DOC_CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                    {(page.workflow_type || ['SOP', 'Runbook'].includes(page.category)) && (
                        <button onClick={newVersion} title="Duplicate as the next version (original stands)"
                            className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">New version</button>
                    )}
                    <button onClick={del} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600">Delete</button>
                </div>
            </div>
            {page.workflow_type ? (
                // A workflow page carries its whole engine surface here too — the same
                // Info | SOP | Script tabs as the onboarding side. One page, one look.
                <OnboardingSetup key={page.id} workflow={{ id: page.id }} onChanged={loadTree} />
            ) : (
                <DocEditor key={page.id} pageId={page.id} initialBody={page.body} onSave={saveBody} />
            )}
            {page.versions?.length > 0 && (
                <div className="mt-8 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Versions</p>
                    <ul className="space-y-1">
                        {page.versions.map((v) => (
                            <li key={v.id} className="flex items-center gap-3 text-sm">
                                <span className="text-gray-700 dark:text-gray-200">{v.version ? `v${v.version}` : 'Earlier version'}</span>
                                <span className="text-xs text-gray-400">{v.updated_at ? new Date(v.updated_at).toLocaleDateString() : ''}</span>
                                <button onClick={() => openPage(v.id)} className="text-blue-600 dark:text-blue-400 hover:underline text-xs">open</button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    ) : (
        <div className="h-full flex items-center justify-center text-gray-400 text-sm">Select or create a page</div>
    );

    return (
        <>
            <Head title="Docs" />
            <AppShell active="docs" nav={nav} detail={detail}
                footer={<div className="flex w-full justify-between"><span>{space?.name || 'Docs'} — {countPages(tree)} pages · {spaces.length} spaces</span><span className="text-gray-400">{status}</span></div>} />
        </>
    );
}

function Tree({ nodes, depth, selectedId, onSelect, onAddChild, collapsed, onToggle, onMove }) {
    return (
        <ul>
            {nodes.map((n) => {
                const hasKids = n.children?.length > 0;
                const isCollapsed = collapsed.has(n.id);
                return (
                    <li key={n.id}>
                        <div draggable
                            onDragStart={(e) => { e.dataTransfer.setData('doc-id', String(n.id)); e.dataTransfer.effectAllowed = 'move'; }}
                            onDragOver={(e) => { e.preventDefault(); e.stopPropagation(); e.currentTarget.classList.add('ring-1', 'ring-blue-400'); }}
                            onDragLeave={(e) => e.currentTarget.classList.remove('ring-1', 'ring-blue-400')}
                            onDrop={(e) => { e.preventDefault(); e.stopPropagation(); e.currentTarget.classList.remove('ring-1', 'ring-blue-400'); const id = Number(e.dataTransfer.getData('doc-id')); if (id && id !== n.id) onMove(id, n.id); }}
                            className={`group flex items-center pr-2 hover:bg-blue-50/60 dark:hover:bg-gray-800 ${selectedId === n.id ? 'bg-blue-50 dark:bg-blue-500/10' : ''}`} style={{ paddingLeft: `${6 + depth * 14}px` }}>
                            {hasKids ? (
                                <button onClick={() => onToggle(n.id)} title={isCollapsed ? 'Expand' : 'Collapse'}
                                    className="w-5 shrink-0 flex justify-center text-gray-500 hover:text-gray-800 dark:hover:text-gray-100"><Chevron open={!isCollapsed} /></button>
                            ) : <span className="w-5 shrink-0" />}
                            <button onClick={() => onSelect(n.id)} className="flex-1 min-w-0 flex items-center gap-1.5 text-left py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                <DocIcon className="h-4 w-4 shrink-0 text-gray-400" /><span className="truncate">{n.title}</span>
                            </button>
                            <CategoryBadge category={n.category} />
                            <button onClick={() => onAddChild(n.id)} title="Add sub-page" className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-blue-600 px-1"><PlusIcon /></button>
                        </div>
                        {hasKids && !isCollapsed && <Tree nodes={n.children} depth={depth + 1} selectedId={selectedId} onSelect={onSelect} onAddChild={onAddChild} collapsed={collapsed} onToggle={onToggle} onMove={onMove} />}
                    </li>
                );
            })}
        </ul>
    );
}
function countPages(nodes) { return (nodes || []).reduce((s, n) => s + 1 + countPages(n.children), 0); }

function spaceInitials(name) {
    return ((name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('') || '?').toUpperCase();
}
function SpaceAvatar({ space, size = 'h-6 w-6 text-[10px]' }) {
    return (
        <span className={`inline-flex ${size} items-center justify-center rounded-md font-semibold text-white shrink-0`}
            style={{ background: space?.color || '#64748b' }}>{spaceInitials(space?.name)}</span>
    );
}
function SpaceSwitcher({ spaces, space, onPick, onNew }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    useEffect(() => {
        const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, []);
    return (
        <div className="relative" ref={ref}>
            <button onClick={() => setOpen((o) => !o)} className="w-full flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800">
                <SpaceAvatar space={space} />
                <span className="flex-1 min-w-0 truncate text-left text-sm font-semibold text-gray-800 dark:text-gray-100">{space?.name || 'Spaces'}</span>
                <Chevron open className="h-4 w-4 text-gray-500" />
            </button>
            {open && (
                <div className="absolute z-20 mt-1 w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1 max-h-80 overflow-y-auto">
                    <div className="px-3 py-1 text-[11px] uppercase tracking-wide text-gray-400">Spaces</div>
                    {spaces.map((s) => (
                        <button key={s.id} onClick={() => { onPick(s.id); setOpen(false); }}
                            className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700 ${s.id === space?.id ? 'bg-blue-50 dark:bg-blue-500/10' : ''}`}>
                            <SpaceAvatar space={s} />
                            <span className="flex-1 min-w-0 truncate text-gray-700 dark:text-gray-200">{s.name}</span>
                            <span className="text-xs text-gray-400">{s.pages}</span>
                        </button>
                    ))}
                    <button onClick={() => { setOpen(false); onNew(); }}
                        className="flex w-full items-center gap-2 px-3 py-2 mt-1 text-left text-sm text-blue-600 hover:bg-gray-50 dark:hover:bg-gray-700 border-t border-gray-100 dark:border-gray-700">
                        <PlusIcon /> New space
                    </button>
                </div>
            )}
        </div>
    );
}
function flatten(nodes, depth = 0) { return (nodes || []).flatMap((n) => [{ id: n.id, title: n.title, icon: n.icon, category: n.category, depth }, ...flatten(n.children, depth + 1)]); }

function CategoryBadge({ category }) {
    if (!category) return null;
    return <span className={`shrink-0 text-[10px] px-1.5 py-0.5 rounded ${CATEGORY_STYLE[category] || CATEGORY_STYLE.Reference}`}>{category}</span>;
}
