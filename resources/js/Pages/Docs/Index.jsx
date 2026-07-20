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
    const { tenant, auth } = usePage().props;
    const me = [auth?.user?.name, auth?.user?.last].filter(Boolean).join(' ');
    const activeId = tenant?.activeId ?? 'all';
    const scope = `sel:docs:${activeId}`;
    const spaceScope = `docs:space:${activeId}`;
    const [spaces, setSpaces] = useState([]);
    const [spaceId, setSpaceId] = useState(() => getLast(spaceScope));
    const [tree, setTree] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [page, setPage] = useState(null);
    const [status, setStatus] = useState('');
    const [conflict, setConflict] = useState(null);   // { mine } — unsaved buffer at conflict time
    const [editorRev, setEditorRev] = useState(0);    // re-key the editor after a conflict reload
    const revRef = useRef(0);                         // the doc revision this view loaded
    const [navTab, setNavTab] = useState('docs');   // docs | templates | commands
    const [snips, setSnips] = useState([]);
    const [selSnipId, setSelSnipId] = useState(null);
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
            .then((p) => {
                if (p && p.id) { setPage(p); revRef.current = p.rev ?? 0; setConflict(null); }
                else { setSelectedId(null); setLast(scope, null); }
            });
    }, [selectedId]);

    const newPage = async (parentId = null, templateKey = 'freeform') => {
        const { id } = await api('/data/docs', 'POST', {
            parent_id: parentId, space_id: spaceId, title: NEW_TITLES[templateKey] || 'Untitled',
            body: buildDocBody(templateKey, '', { owner: me }), category: templateCategory(templateKey),
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
    // Optimistic lock: every save carries the rev we loaded. A stale buffer
    // (second tab, the Onboarding SOP view) gets a conflict banner instead of
    // silently resurrecting deleted content.
    const saveBody = async (html) => {
        if (conflict) return;   // don't fight a known conflict; the banner decides
        setStatus('Saving…');
        const r = await api(`/data/docs/${selectedId}`, 'PATCH', { body: html, rev: revRef.current });
        if (r?.conflict) { setConflict({ mine: html }); setStatus(''); return; }
        if (r?.rev) revRef.current = r.rev;
        setStatus('Saved');
        setTimeout(() => setStatus(''), 1200);
    };
    const conflictReload = async () => {
        const p = await api(`/data/docs/${selectedId}`);
        setPage(p); revRef.current = p.rev ?? 0; setConflict(null); setEditorRev((v) => v + 1);
    };
    const conflictOverwrite = async () => {
        const html = conflict.mine;
        setConflict(null);
        const r = await api(`/data/docs/${selectedId}`, 'PATCH', { body: html });
        if (r?.rev) revRef.current = r.rev;
        setStatus('Saved'); setTimeout(() => setStatus(''), 1200);
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

    // Promote a plain doc into a runnable workflow (people or device).
    const promote = async (type) => {
        if (!type) return;
        await api(`/data/docs/${selectedId}/promote`, 'POST', { type });
        const p = await api(`/data/docs/${selectedId}`);
        if (p && p.id) setPage(p);
        loadTree();
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

    // The commands registry (Docs > Commands): each entry is a /slash command
    // with per-platform scripts, editable here.
    const loadSnips = () => api('/data/snippets').then((r) => setSnips(Array.isArray(r) ? r : []));
    useEffect(() => { if (navTab === 'commands') loadSnips(); }, [navTab]);
    const newSnippet = async () => {
        const name = prompt('Command name (lowercase, e.g. banner):');
        if (!name?.trim()) return;
        const r = await api('/data/snippets', 'POST', { command: name.trim().toLowerCase() });
        if (r?.id) { await loadSnips(); setSelSnipId(r.id); }
        else alert(r?.message || 'Could not create that command.');
    };
    const selSnip = snips.find((s) => s.id === selSnipId) || null;


    // Incidents are chronological records, not procedure pages: they get their
    // own tab (newest first) and stay OUT of the Docs tree.
    const flatten = (nodes) => nodes.flatMap((n) => [n, ...flatten(n.children || [])]);
    const incidents = flatten(tree).filter((n) => n.category === 'Incident')
        .sort((a, b) => (b.updated_at || '').localeCompare(a.updated_at || ''));
    const pruneIncidents = (nodes) => nodes.filter((n) => n.category !== 'Incident')
        .map((n) => ({ ...n, children: pruneIncidents(n.children || []) }));

    const nav = (
        <div className="flex flex-col h-full">
            <div className="p-2 border-b border-gray-100 dark:border-gray-800">
                <SpaceSwitcher spaces={spaces} space={space} onPick={chooseSpace} onNew={newSpace} />
            </div>
            <div className="flex border-b border-gray-200 dark:border-gray-800 px-2 pt-1">
                {[['docs', 'Docs'], ['incidents', 'Incidents'], ['templates', 'Templates'], ['commands', 'Commands']].map(([k, label]) => (
                    <button key={k} onClick={() => setNavTab(k)}
                        className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${navTab === k ? 'text-blue-600 border-blue-600' : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'}`}>
                        {label}
                    </button>
                ))}
            </div>
            {navTab === 'commands' ? (
                <div className="flex-1 overflow-y-auto p-3 space-y-1">
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Every command here is a <code>/slash</code> command in SOPs; its script joins the machine bootstrap.</p>
                    {snips.map((s) => (
                        <button key={s.id} onClick={() => setSelSnipId(s.id)}
                            className={`w-full rounded-md px-3 py-2 text-left text-sm ${selSnipId === s.id ? 'bg-blue-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'}`}>
                            /{s.command}{s.params ? ` ${s.params.split(',').map((p) => p.trim()).join(' ')}` : ''}
                            {!s.active && <span className="ml-2 text-xs opacity-60">(off)</span>}
                        </button>
                    ))}
                    <button onClick={newSnippet}
                        className="w-full rounded-md px-3 py-2 text-left text-sm text-blue-600 dark:text-blue-400 hover:bg-gray-50 dark:hover:bg-gray-800">+ New command</button>
                </div>
            ) : navTab === 'incidents' ? (
                <div className="flex-1 overflow-y-auto p-3 space-y-1">
                    <button onClick={() => newPage(null, 'incident')}
                        className="w-full rounded-md px-3 py-2 text-left text-sm text-blue-600 dark:text-blue-400 hover:bg-gray-50 dark:hover:bg-gray-800">+ New incident report</button>
                    {incidents.length === 0 && <p className="p-2 text-xs text-gray-400">No incident reports in {space?.name || 'this space'} yet.</p>}
                    {incidents.map((n) => (
                        <button key={n.id} onClick={() => openPage(n.id)}
                            className={`flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm ${selectedId === n.id ? 'bg-blue-50 dark:bg-blue-500/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800'}`}>
                            <span className="flex-1 truncate text-gray-700 dark:text-gray-300">{n.title}</span>
                            <span className="shrink-0 text-xs text-gray-400">{n.updated_at || ''}</span>
                        </button>
                    ))}
                </div>
            ) : navTab === 'templates' ? (
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
                    <div className="px-3 pb-2 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2">
                        <input value={searchQ} onChange={(e) => runSearch(e.target.value)} placeholder="Search all docs…"
                            className="flex-1 rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-xs py-1.5 focus:border-blue-500 focus:ring-blue-500" />
                        <button onClick={() => setAllCollapsed(collapsed.size === 0)}
                            title={collapsed.size === 0 ? 'Collapse all' : 'Expand all'}
                            className="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-sm px-1">
                            {collapsed.size === 0 ? '⊟' : '⊞'}
                        </button>
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
                        ) : (
                            tree.length ? <Tree nodes={pruneIncidents(tree)} depth={0} selectedId={selectedId} onSelect={openPage} onAddChild={newPage} collapsed={collapsed} onToggle={toggleCollapse} onMove={moveDoc} />
                                : <div className="p-4 text-sm text-gray-400">No pages yet. Create one.</div>
                        )}
                    </div>
                </>
            )}
        </div>
    );

    const detail = navTab === 'commands' ? (
        selSnip
            ? <SnippetEditor key={selSnip.id} snippet={selSnip} api={api} onChanged={loadSnips} onDeleted={() => { setSelSnipId(null); loadSnips(); }} />
            : <div className="h-full flex items-center justify-center text-gray-400 text-sm">Pick a command on the left, or create one.</div>
    ) : page ? (
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
                    {!page.workflow_type && (
                        <select value="" onChange={(e) => promote(e.target.value)} title="Make this doc runnable — it compiles to steps, tasks and scripts"
                            className="rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 text-sm py-1.5 focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Static doc</option>
                            <option value="people">Run as People workflow</option>
                            <option value="device">Run as Device workflow</option>
                        </select>
                    )}
                    {(page.workflow_type || ['SOP', 'Runbook'].includes(page.category)) && (
                        <button onClick={newVersion} title="Duplicate as the next version (original stands)"
                            className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">New version</button>
                    )}
                    <ShareMenu page={page} onChanged={(ids) => setPage((p) => ({ ...p, shared_company_ids: ids }))} />
                    <button onClick={del} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600">Delete</button>
                </div>
            </div>
            {page.workflow_type ? (
                // A workflow page carries its whole engine surface here too — the same
                // Info | SOP | Script tabs as the onboarding side. One page, one look.
                <OnboardingSetup key={page.id} workflow={{ id: page.id }} onChanged={loadTree} />
            ) : (
                <>
                    {conflict && (
                        <div className="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-500/10 px-4 py-2 text-sm text-amber-800 dark:text-amber-300">
                            <span>This page changed in another view (second tab, or the Onboarding SOP tab). Your edits here are <strong>not saved</strong>.</span>
                            <button onClick={conflictReload} className="px-2 py-1 rounded border border-amber-400 hover:bg-amber-100 dark:hover:bg-amber-500/20">Load the latest</button>
                            <button onClick={conflictOverwrite} className="px-2 py-1 rounded border border-amber-400 hover:bg-amber-100 dark:hover:bg-amber-500/20">Keep mine (overwrite)</button>
                        </div>
                    )}
                    <DocEditor key={`${page.id}:${editorRev}`} pageId={page.id} initialBody={page.body} onSave={saveBody} ownerDefault={me} companyId={page.company_id} />
                </>
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

/** Show this doc in other companies too — one playbook for parent + child
 *  companies instead of two copies. Owner company stays the owner. */
function ShareMenu({ page, onChanged }) {
    const [open, setOpen] = useState(false);
    const [opts, setOpts] = useState(null);
    useEffect(() => { if (open && opts === null) api('/data/company-options').then(setOpts); }, [open]);
    const ids = page.shared_company_ids || [];
    const toggle = async (cid) => {
        const next = ids.includes(cid) ? ids.filter((x) => x !== cid) : [...ids, cid];
        onChanged(next);
        await api(`/data/docs/${page.id}`, 'PATCH', { shared_company_ids: next });
    };
    const others = (opts || []).filter((o) => o.id !== page.company_id);
    return (
        <div className="relative">
            <button onClick={() => setOpen((v) => !v)} title="Show this doc in other companies too (one playbook, no copies)"
                className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                Shared{ids.length ? `: +${ids.length}` : ''}
            </button>
            {open && (
                <div className="absolute right-0 z-20 mt-1 w-60 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-2 shadow-lg">
                    <p className="px-1 pb-1 text-xs text-gray-400">Also show in…</p>
                    {others.length === 0 && <p className="px-1 py-1 text-sm text-gray-400">{opts === null ? 'Loading…' : 'No other companies.'}</p>}
                    {others.map((o) => (
                        <label key={o.id} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-800">
                            <input type="checkbox" checked={ids.includes(o.id)} onChange={() => toggle(o.id)}
                                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                            <span className="text-gray-700 dark:text-gray-300">{o.label}</span>
                        </label>
                    ))}
                </div>
            )}
        </div>
    );
}

function CategoryBadge({ category }) {
    if (!category) return null;
    return <span className={`shrink-0 text-[10px] px-1.5 py-0.5 rounded ${CATEGORY_STYLE[category] || CATEGORY_STYLE.Reference}`}>{category}</span>;
}

/**
 * One command of the registry: its label, ordered params, and the three platform
 * scripts. {param} names, {1}.. and {*} substitute from the SOP's arguments;
 * context vars come free.
 */
function SnippetEditor({ snippet, api, onChanged, onDeleted }) {
    const [label, setLabel] = useState(snippet.label || '');
    const [params, setParams] = useState(snippet.params || '');
    const [scripts, setScripts] = useState({
        mac_script: snippet.mac_script || '', windows_script: snippet.windows_script || '', linux_script: snippet.linux_script || '',
    });
    const [platform, setPlatform] = useState('mac_script');
    const [active, setActive] = useState(!!snippet.active);
    const [saved, setSaved] = useState('');

    const save = async () => {
        await api(`/data/snippets/${snippet.id}`, 'PATCH', { label, params, active, ...scripts });
        setSaved('Saved'); setTimeout(() => setSaved(''), 1200);
        onChanged?.();
    };
    const del = async () => {
        if (!confirm(`Delete /${snippet.command}?`)) return;
        await api(`/data/snippets/${snippet.id}`, 'DELETE');
        onDeleted?.();
    };

    const paramNames = params.split(',').map((p) => p.trim()).filter(Boolean);
    return (
        <div className="max-w-3xl mx-auto">
            <div className="flex items-start justify-between mb-2 gap-4">
                <h1 className="text-3xl font-bold text-gray-900 dark:text-white">/{snippet.command}</h1>
                <div className="mt-2 shrink-0 flex items-center gap-2">
                    {saved && <span className="text-xs text-green-600">{saved}</span>}
                    <button onClick={save} className="px-3 py-1.5 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700">Save</button>
                    {!snippet.shipped && (
                        <button onClick={del} className="px-3 py-1.5 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-red-600">Delete</button>
                    )}
                </div>
            </div>
            {snippet.shipped && <p className="mb-3 text-xs text-gray-400">Shipped command — edits apply to every company.</p>}
            <div className="grid grid-cols-2 gap-3 mb-3">
                <label className="block">
                    <span className="block text-xs uppercase tracking-wide text-gray-400 mb-1">Label — what it does</span>
                    <input value={label} onChange={(e) => setLabel(e.target.value)}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                </label>
                <label className="block">
                    <span className="block text-xs uppercase tracking-wide text-gray-400 mb-1">Params — ordered, comma separated (e.g. ssid, psk)</span>
                    <input value={params} onChange={(e) => setParams(e.target.value)}
                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                </label>
            </div>
            <div className="flex border-b border-gray-200 dark:border-gray-800 mb-2">
                {[['mac_script', 'Mac Script'], ['windows_script', 'Win Script'], ['linux_script', 'Linux Script']].map(([k, l]) => (
                    <button key={k} onClick={() => setPlatform(k)}
                        className={`px-3 py-2 text-sm font-medium border-b-2 -mb-px ${platform === k ? 'text-blue-600 border-blue-600' : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200'}`}>
                        {l}
                    </button>
                ))}
            </div>
            <textarea value={scripts[platform]} onChange={(e) => setScripts((s) => ({ ...s, [platform]: e.target.value }))}
                rows={14} spellCheck={false}
                className="w-full rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-950 p-4 font-mono text-xs leading-relaxed text-green-300 focus:border-blue-500 focus:ring-blue-500" />
            <p className="mt-2 text-xs text-gray-400">
                Variables: {paramNames.length ? paramNames.map((p) => `{${p}}`).join(' ') + ' · ' : ''}
                {'{*} {1}… · {ASSET_TAG} {BASE_URL} {TOKEN} {REPO} {DOMAIN} {LOCAL_DOMAIN} {LOCAL_ADMIN_USER} {LOCAL_ADMIN_PASS} {DOMAIN_JOIN_USER} {DOMAIN_JOIN_PASS}'} — <code>report 'step' true 'note'</code> ticks the checklist.
                Params given in the SOP bake in; params left out make the script <em>ask and wait</em> at run time (secret-ish names prompt silently).
            </p>
            <label className="mt-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
                <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)}
                    className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                Active — shows in the slash menu and joins generated scripts
            </label>
        </div>
    );
}
