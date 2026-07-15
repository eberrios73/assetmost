import { PlusIcon } from "@/Components/Icons";
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppShell from '@/Layouts/AppShell';
import DocEditor from '@/Components/DocEditor';
import TemplateMenu from '@/Components/TemplateMenu';
import { buildDocBody, templateIcon } from '@/docTemplates';
import { getLast, setLast } from '@/lib/lastView';

const NEW_TITLES = { sop: 'New SOP', troubleshooting: 'New troubleshooting guide', freeform: 'Untitled' };

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
    const titleTimer = useRef(null);

    const openPage = (id) => { setSelectedId(id); setLast(scope, id); };
    const loadTree = () => api('/data/docs').then(setTree);
    useEffect(() => { loadTree(); }, []);
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
            body: buildDocBody(templateKey), icon: templateIcon(templateKey),
        });
        await loadTree();
        openPage(id);
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

    const nav = (
        <div className="flex flex-col h-full">
            <div className="p-3 border-b border-gray-100 flex items-center justify-between">
                <span className="text-sm font-semibold text-gray-700 dark:text-gray-200">Pages</span>
                <TemplateMenu label="New" glyph={<PlusIcon />} onPick={(k) => newPage(null, k)}
                    className="text-xs rounded-md bg-blue-600 text-white px-2 py-1 hover:bg-blue-700 inline-flex items-center gap-1" />
            </div>
            <div className="flex-1 overflow-y-auto py-1">
                {tree.length ? <Tree nodes={tree} depth={0} selectedId={selectedId} onSelect={openPage} onAddChild={newPage} />
                    : <div className="p-4 text-sm text-gray-400">No pages yet. Create one.</div>}
            </div>
        </div>
    );

    const detail = page ? (
        <div className="max-w-3xl mx-auto">
            <div className="flex items-start justify-between mb-2 gap-4">
                <input value={page.title} onChange={(e) => saveTitle(e.target.value)}
                    className="flex-1 text-3xl font-bold text-gray-900 dark:text-white border-0 focus:ring-0 px-0 placeholder-gray-300" placeholder="Untitled" />
                <button onClick={del} className="mt-2 shrink-0 px-3 py-1.5 text-sm rounded-md border border-gray-200 text-gray-500 hover:text-red-600">Delete</button>
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

function Tree({ nodes, depth, selectedId, onSelect, onAddChild }) {
    return (
        <ul>
            {nodes.map((n) => (
                <li key={n.id}>
                    <div className={`group flex items-center gap-1 pr-2 hover:bg-blue-50/60 ${selectedId === n.id ? 'bg-blue-50' : ''}`} style={{ paddingLeft: `${12 + depth * 14}px` }}>
                        <button onClick={() => onSelect(n.id)} className="flex-1 text-left py-1.5 text-sm text-gray-700 dark:text-gray-300 truncate">
                            <span className="mr-1">{n.icon || '📄'}</span>{n.title}
                        </button>
                        <button onClick={() => onAddChild(n.id)} title="Add sub-page" className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-blue-600 px-1"><PlusIcon /></button>
                    </div>
                    {n.children?.length > 0 && <Tree nodes={n.children} depth={depth + 1} selectedId={selectedId} onSelect={onSelect} onAddChild={onAddChild} />}
                </li>
            ))}
        </ul>
    );
}
function countPages(nodes) { return (nodes || []).reduce((s, n) => s + 1 + countPages(n.children), 0); }
