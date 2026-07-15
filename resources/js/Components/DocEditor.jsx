import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { marked } from 'marked';
import { useEffect, useRef, useState } from 'react';

// seeded docs are markdown; once saved they're HTML. Detect and normalize to HTML.
const toHtml = (body) => {
    if (!body) return '';
    return /^\s*</.test(body) ? body : marked.parse(body, { breaks: true });
};

const SLASH = [
    { key: 'p', label: 'Text', hint: 'Plain paragraph', run: (e) => e.chain().focus().setParagraph().run() },
    { key: 'h1', label: 'Heading 1', hint: 'Big section heading', run: (e) => e.chain().focus().toggleHeading({ level: 1 }).run() },
    { key: 'h2', label: 'Heading 2', hint: 'Medium heading', run: (e) => e.chain().focus().toggleHeading({ level: 2 }).run() },
    { key: 'h3', label: 'Heading 3', hint: 'Small heading', run: (e) => e.chain().focus().toggleHeading({ level: 3 }).run() },
    { key: 'ul', label: 'Bulleted list', hint: 'Simple bullets', run: (e) => e.chain().focus().toggleBulletList().run() },
    { key: 'ol', label: 'Numbered list', hint: 'Ordered steps', run: (e) => e.chain().focus().toggleOrderedList().run() },
    { key: 'quote', label: 'Quote', hint: 'Callout / block quote', run: (e) => e.chain().focus().toggleBlockquote().run() },
    { key: 'code', label: 'Code block', hint: 'Monospace snippet', run: (e) => e.chain().focus().toggleCodeBlock().run() },
    { key: 'hr', label: 'Divider', hint: 'Horizontal rule', run: (e) => e.chain().focus().setHorizontalRule().run() },
];

/** Notion/Docmost-style canvas: rich text + "/" slash menu. Autosaves HTML (debounced). */
export default function DocEditor({ pageId, initialBody, onSave }) {
    const [menu, setMenu] = useState(null); // { query, from, x, y, index }
    const saveTimer = useRef(null);
    const menuRef = useRef(null);

    const editor = useEditor({
        extensions: [
            StarterKit,
            Placeholder.configure({ placeholder: "Type '/' for commands, or just start writing…" }),
        ],
        content: toHtml(initialBody),
        editorProps: { attributes: { class: 'prose prose-sm dark:prose-invert max-w-none focus:outline-none min-h-[65vh] pb-24' } },
        onUpdate: ({ editor }) => {
            detectSlash(editor);
            clearTimeout(saveTimer.current);
            saveTimer.current = setTimeout(() => onSave(editor.getHTML()), 700);
        },
        onSelectionUpdate: ({ editor }) => detectSlash(editor),
    }, [pageId]);

    const detectSlash = (ed) => {
        const { $from, empty } = ed.state.selection;
        if (!empty) return setMenu(null);
        const textBefore = $from.parent.textBetween(0, $from.parentOffset, '\n', '\0');
        const m = textBefore.match(/^\/(\w*)$/);
        if (!m) return setMenu(null);
        const coords = ed.view.coordsAtPos($from.pos);
        setMenu({ query: m[1].toLowerCase(), from: $from.pos - m[0].length, to: $from.pos, x: coords.left, y: coords.bottom, index: 0 });
    };

    const items = menu ? SLASH.filter((s) => s.label.toLowerCase().includes(menu.query)) : [];

    const apply = (item) => {
        if (!editor || !item) return;
        editor.chain().focus().deleteRange({ from: menu.from, to: menu.to }).run();
        item.run(editor);
        setMenu(null);
    };

    // keyboard nav for the slash menu
    useEffect(() => {
        if (!menu) return;
        const onKey = (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); setMenu((m) => ({ ...m, index: Math.min(m.index + 1, items.length - 1) })); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setMenu((m) => ({ ...m, index: Math.max(m.index - 1, 0) })); }
            else if (e.key === 'Enter') { e.preventDefault(); apply(items[menu.index]); }
            else if (e.key === 'Escape') setMenu(null);
        };
        document.addEventListener('keydown', onKey, true);
        return () => document.removeEventListener('keydown', onKey, true);
    }, [menu, items]);

    useEffect(() => () => clearTimeout(saveTimer.current), []);

    return (
        <div className="relative">
            <EditorContent editor={editor} />
            {menu && items.length > 0 && (
                <div ref={menuRef} style={{ position: 'fixed', left: menu.x, top: menu.y + 4, zIndex: 60 }}
                    className="w-64 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-xl py-1 text-sm">
                    {items.map((it, i) => (
                        <button key={it.key} onMouseDown={(e) => { e.preventDefault(); apply(it); }}
                            className={`w-full text-left px-3 py-1.5 flex flex-col ${i === menu.index ? 'bg-blue-50 dark:bg-blue-500/15' : 'hover:bg-gray-50 dark:hover:bg-gray-700'}`}>
                            <span className="text-gray-800 dark:text-gray-100">{it.label}</span>
                            <span className="text-xs text-gray-400">{it.hint}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
