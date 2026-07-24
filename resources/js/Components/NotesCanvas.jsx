import { useEditor, EditorContent } from '@tiptap/react';
import { Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import { useEffect, useRef, useState } from 'react';

/**
 * Notes on steroids: the task's notes as a small canvas. URLs autolink,
 * @-mentions drop the same durable pills docs use, and a pasted screenshot
 * uploads to the server and lands inline. One surface, the whole grammar —
 * no separate fields for links or attachments.
 *
 * Same objRef serialization as the docs editor (data-ref="device:12"), so
 * task notes feed the reference graph exactly like doc bodies do.
 */
const ObjRef = Node.create({
    name: 'objRef',
    group: 'inline',
    inline: true,
    atom: true,
    addAttributes() {
        return { rtype: { default: null }, rid: { default: null }, label: { default: '' } };
    },
    parseHTML() {
        return [{
            tag: 'span[data-ref]',
            getAttrs: (el) => {
                const [rtype, rid] = (el.getAttribute('data-ref') || ':').split(':');
                return { rtype, rid: Number(rid), label: el.textContent.replace(/^@/, '') };
            },
        }];
    },
    renderHTML({ node }) {
        return ['span', { 'data-ref': `${node.attrs.rtype}:${node.attrs.rid}`, class: 'obj-ref' }, `@${node.attrs.label}`];
    },
});

// A link ends where its URL ends: not inclusive, so typing after it is plain
// text again ("you can paste the link but you can't get out" — now you can).
// Click opens in a new tab; edit with the keyboard or by clicking beside it.
const NotesLink = Link.extend({ inclusive: () => false }).configure({
    openOnClick: true, autolink: true,
    HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer' },
});

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');

// Legacy notes are plain text; the canvas speaks HTML. Escape and paragraph
// the old ones, pass HTML through — the first save upgrades the row for good.
const toHtml = (v) => {
    if (!v) return '';
    if (/^\s*</.test(v)) return v;
    const esc = v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return esc.split('\n').filter((l) => l.trim() !== '').map((l) => `<p>${l}</p>`).join('') || '';
};

const uploadImage = async (file) => {
    const form = new FormData();
    form.append('image', file);
    const r = await fetch('/data/uploads', {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: form,
    });
    return r.ok ? (await r.json()).url : null;
};

export default function NotesCanvas({ value, onCommit }) {
    const [menu, setMenu] = useState(null);        // { query, from, to, x, y }
    const [results, setResults] = useState([]);
    const [idx, setIdx] = useState(0);
    const saveTimer = useRef(null);
    const fetchTimer = useRef(null);
    // The editor captures its handlers once — refs keep them seeing the present.
    const menuRef = useRef(null); useEffect(() => { menuRef.current = menu; }, [menu]);
    const resultsRef = useRef([]); useEffect(() => { resultsRef.current = results; setIdx(0); }, [results]);
    const idxRef = useRef(0); useEffect(() => { idxRef.current = idx; }, [idx]);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ heading: { levels: [3] }, link: false }),
            NotesLink,
            ObjRef,
            Image.configure({ inline: false }),
            Placeholder.configure({ placeholder: 'Notes — paste links or screenshots, @ mentions the inventory…' }),
        ],
        content: toHtml(value),
        editorProps: {
            attributes: { class: 'prose prose-sm dark:prose-invert max-w-none focus:outline-none min-h-[72px] rounded-md border border-gray-200 dark:border-gray-700 dark:bg-gray-800 px-3 py-2 text-sm' },
            handleKeyDown: (view, event) => {
                if (!menuRef.current) return false;
                if (event.key === 'Escape') { setMenu(null); return true; }
                if (!resultsRef.current.length) return false;
                if (event.key === 'ArrowDown') { setIdx((i) => Math.min(i + 1, resultsRef.current.length - 1)); return true; }
                if (event.key === 'ArrowUp') { setIdx((i) => Math.max(i - 1, 0)); return true; }
                if (event.key === 'Enter') { pickRef.current(resultsRef.current[idxRef.current]); return true; }
                return false;
            },
            handlePaste: (view, event) => {
                const img = [...(event.clipboardData?.items || [])].find((i) => i.type.startsWith('image/'));
                if (!img) return false;
                const file = img.getAsFile();
                if (!file) return false;
                uploadImage(file).then((url) => {
                    if (url) editor?.chain().focus().setImage({ src: url }).run();
                });
                return true;
            },
        },
        onUpdate: ({ editor }) => {
            detectAt(editor);
            clearTimeout(saveTimer.current);
            saveTimer.current = setTimeout(() => onCommit(editor.getHTML()), 600);
        },
        onSelectionUpdate: ({ editor }) => detectAt(editor),
    }, []);

    const detectAt = (ed) => {
        const { $from, empty } = ed.state.selection;
        if (!empty) return setMenu(null);
        const textBefore = $from.parent.textBetween(0, $from.parentOffset, '\n', '\0');
        const at = textBefore.match(/(?:^|\s)@([\w .-]*)$/);
        if (!at) return setMenu(null);
        const coords = ed.view.coordsAtPos($from.pos);
        setMenu({ query: at[1].toLowerCase(), from: $from.pos - at[1].length - 1, to: $from.pos, x: coords.left, y: coords.bottom });
    };

    useEffect(() => {
        clearTimeout(fetchTimer.current);
        if (!menu || menu.query.length < 2) { setResults([]); return; }
        fetchTimer.current = setTimeout(() => {
            fetch(`/data/palette-search?q=${encodeURIComponent(menu.query)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json()).then((d) => setResults(d.results || [])).catch(() => setResults([]));
        }, 150);
    }, [menu?.query]);

    const pickRef = useRef(() => {});
    const pick = (r) => {
        if (!r) return;
        editor.chain().focus()
            .deleteRange({ from: menu.from, to: menu.to })
            .insertContentAt(menu.from, [
                { type: 'objRef', attrs: { rtype: r.type, rid: r.id, label: r.label } },
                { type: 'text', text: ' ' },
            ]).run();
        setMenu(null);
    };
    pickRef.current = pick;

    return (
        <div className="relative">
            <EditorContent editor={editor} />
            {menu && results.length > 0 && (
                <div className="fixed z-[90] w-72 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-xl"
                    style={{ left: menu.x, top: menu.y + 4 }}>
                    {results.slice(0, 6).map((r, i) => (
                        <button key={`${r.type}-${r.id}`} onMouseDown={(e) => { e.preventDefault(); pick(r); }} onMouseEnter={() => setIdx(i)}
                            className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm ${i === idx ? 'bg-blue-50 dark:bg-blue-500/15' : ''}`}>
                            <span className="shrink-0 whitespace-nowrap font-medium text-gray-800 dark:text-gray-100">@{r.label}</span>
                            <span className="min-w-0 truncate text-xs text-gray-400">{r.sub || r.type}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
