import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableHeader } from '@tiptap/extension-table-header';
import { TableCell } from '@tiptap/extension-table-cell';
import CodeBlock from '@tiptap/extension-code-block';
import { marked } from 'marked';
import { useEffect, useRef, useState } from 'react';

// Code block with a hover "Copy" button (great for runbook commands). The button
// lives in the node-view wrapper (contentEditable=false) so it never becomes part
// of the copied text or the editable content.
const CodeBlockWithCopy = CodeBlock.extend({
    addNodeView() {
        return () => {
            const wrapper = document.createElement('div');
            wrapper.className = 'relative group';
            const pre = document.createElement('pre');
            const code = document.createElement('code');
            pre.appendChild(code);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.contentEditable = 'false';
            btn.textContent = 'Copy';
            btn.className = 'absolute top-2 right-2 px-2 py-0.5 text-xs rounded bg-gray-700/90 text-gray-100 opacity-0 group-hover:opacity-100 hover:bg-gray-600 transition-opacity';
            btn.addEventListener('mousedown', (e) => e.preventDefault());
            btn.addEventListener('click', () => {
                navigator.clipboard?.writeText(code.textContent || '');
                btn.textContent = 'Copied';
                setTimeout(() => { btn.textContent = 'Copy'; }, 1200);
            });
            wrapper.append(btn, pre);
            return { dom: wrapper, contentDOM: code };
        };
    },
});

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
    { key: 'table', label: 'Table', hint: '3×3 with header row', run: (e) => e.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run() },
    { key: 'hr', label: 'Divider', hint: 'Horizontal rule', run: (e) => e.chain().focus().setHorizontalRule().run() },
];

/** Notion/Docmost-style canvas: rich text + "/" slash menu. Autosaves HTML (debounced). */
export default function DocEditor({ pageId, initialBody, onSave }) {
    const [menu, setMenu] = useState(null); // { query, from, x, y, index }
    const [refs, setRefs] = useState([]);         // runbook references: [{slug, name}]
    const [installers, setInstallers] = useState([]);   // indexed installers share
    const saveTimer = useRef(null);
    const menuRef = useRef(null);

    // Runbook references + installers you can drop into a doc with "/". Typing
    // /eprotection references the current runbook; /install <name> picks software;
    // /vpn <profile> picks a VPN config — both resolve to "fetch from share + install".
    useEffect(() => {
        fetch('/data/runbook-refs', { headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then(setRefs).catch(() => {});
        fetch('/data/installers', { headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then(setInstallers).catch(() => {});
    }, []);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ codeBlock: false }),
            CodeBlockWithCopy,
            Placeholder.configure({ placeholder: "Type '/' for commands, or just start writing…" }),
            Table.configure({ resizable: true }),
            TableRow,
            TableHeader,
            TableCell,
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
        // "/install office" / "/vpn profile" — commands that take an argument, resolved
        // by the build script to "download this from our share and install it".
        const inst = textBefore.match(/\/install\s+([^/]*)$/i);
        if (inst) {
            const coords = ed.view.coordsAtPos($from.pos);
            return setMenu({ mode: 'install', query: inst[1].trim().toLowerCase(), from: $from.pos - inst[0].length, to: $from.pos, x: coords.left, y: coords.bottom, yTop: coords.top, index: 0 });
        }
        const vpn = textBefore.match(/\/vpn\s+([^/]*)$/i);
        if (vpn) {
            const coords = ed.view.coordsAtPos($from.pos);
            return setMenu({ mode: 'vpn', query: vpn[1].trim().toLowerCase(), from: $from.pos - vpn[0].length, to: $from.pos, x: coords.left, y: coords.bottom, yTop: coords.top, index: 0 });
        }
        const mdm = textBefore.match(/\/mdm\s+([^/]*)$/i);
        if (mdm) {
            const coords = ed.view.coordsAtPos($from.pos);
            return setMenu({ mode: 'mdm', query: mdm[1].trim().toLowerCase(), from: $from.pos - mdm[0].length, to: $from.pos, x: coords.left, y: coords.bottom, yTop: coords.top, index: 0 });
        }
        const form = textBefore.match(/\/form\s+([^/]*)$/i);
        if (form) {
            const coords = ed.view.coordsAtPos($from.pos);
            return setMenu({ mode: 'form', query: form[1].trim().toLowerCase(), from: $from.pos - form[0].length, to: $from.pos, x: coords.left, y: coords.bottom, yTop: coords.top, index: 0 });
        }
        // "/word" — commands and runbook references.
        const m = textBefore.match(/(?:^|\s)\/(\w*)$/);
        if (!m) return setMenu(null);
        const coords = ed.view.coordsAtPos($from.pos);
        setMenu({ mode: 'slash', query: m[1].toLowerCase(), from: $from.pos - m[1].length - 1, to: $from.pos, x: coords.left, y: coords.bottom, yTop: coords.top, index: 0 });
    };

    // Reference items are runbooks: picking one inserts the /slug token as plain text —
    // the reference lives IN the document and resolves when a machine is built.
    const refItems = refs.map((r) => ({
        key: `ref:${r.slug}`, label: `↳ ${r.name}`, hint: `Insert /${r.slug} reference`,
        isRef: true, slug: r.slug,
        run: (e) => e.chain().focus().insertContent(`/${r.slug} `).run(),
    }));

    // /install <platform> <software>: the first word picks the platform (mac/windows),
    // the rest narrows by name — "/install mac" lists the Mac software, "/install mac
    // office" narrows to Office. Whatever's typed can always be inserted (works before
    // the share is indexed too).
    const PLAT = { mac: 'mac', macos: 'mac', osx: 'mac', apple: 'mac', win: 'windows', windows: 'windows', pc: 'windows' };
    // VPN profiles are config files, not software — kept out of /install, and are the
    // only thing /vpn shows.
    const VPN_RE = /\.(ovpn|ovpn12|mobileconfig|tblk|visc|visz|conf|wg)$/i;
    let items;
    if (menu?.mode === 'install') {
        const words = menu.query.split(/\s+/).filter(Boolean);
        let platform = null;
        if (words.length && PLAT[words[0]]) { platform = PLAT[words[0]]; words.shift(); }
        const nameQ = words.join(' ');
        const picks = installers
            .filter((i) => !VPN_RE.test(i.name))                                   // software only
            .filter((i) => !platform || (i.platform || '').toLowerCase() === platform)
            .filter((i) => !nameQ || i.name.toLowerCase().includes(nameQ))
            .slice(0, 8)
            .map((i) => ({ key: `inst:${i.id}`, label: i.name, hint: `${i.platform}${i.arch ? ' ' + i.arch + '-bit' : ''}`,
                run: (e) => e.chain().focus().insertContent(`/install ${i.name} `).run() }));
        // Works before the share is indexed: keep whatever was typed as the reference.
        if (nameQ) picks.push({ key: 'inst:free', label: `Use "${nameQ}"`, hint: 'insert as typed',
            run: (e) => e.chain().focus().insertContent(`/install ${menu.query} `).run() });
        items = picks;
    } else if (menu?.mode === 'vpn') {
        const picks = installers
            .filter((i) => VPN_RE.test(i.name))                                    // VPN profiles only
            .filter((i) => !menu.query || i.name.toLowerCase().includes(menu.query))
            .slice(0, 8)
            .map((i) => ({ key: `vpn:${i.id}`, label: i.name.replace(VPN_RE, ''), hint: 'VPN profile — download + install',
                run: (e) => e.chain().focus().insertContent(`/vpn ${i.name} `).run() }));
        if (menu.query) picks.push({ key: 'vpn:free', label: `Use "${menu.query}"`, hint: 'insert as typed',
            run: (e) => e.chain().focus().insertContent(`/vpn ${menu.query} `).run() });
        items = picks;
    } else if (menu?.mode === 'mdm') {
        // A fixed list, not the share — /mdm names the MDM the bootstrap script enrolls into.
        const MDM = ['Jamf', 'Intune', 'Kandji', 'Mosyle', 'Addigy', 'Workspace ONE'];
        const picks = MDM.filter((n) => !menu.query || n.toLowerCase().includes(menu.query))
            .map((n) => ({ key: `mdm:${n}`, label: n, hint: 'Enroll into this MDM (read from the SOP)',
                run: (e) => e.chain().focus().insertContent(`/mdm ${n} `).run() }));
        if (menu.query) picks.push({ key: 'mdm:free', label: `Use "${menu.query}"`, hint: 'insert as typed',
            run: (e) => e.chain().focus().insertContent(`/mdm ${menu.query} `).run() });
        items = picks;
    } else if (menu?.mode === 'form') {
        // /form <new|edit> <kind>: the step's generated task carries the record form —
        // new creates, edit picks an existing record and updates it. Both in the
        // workflow's company. The Record field made executable.
        const KINDS = ['device', 'person', 'account', 'location'];
        items = ['new', 'edit'].flatMap((mode) => KINDS.map((k) => ({
            key: `form:${mode}:${k}`,
            label: `${mode === 'new' ? 'New' : 'Edit'} ${k}`,
            hint: mode === 'new' ? `The task gets an "Add ${k}" form` : `The task gets a pick-and-edit ${k} form`,
            run: (e) => e.chain().focus().insertContent(`/form ${mode} ${k} `).run(),
        }))).filter((it) => !menu.query || it.label.toLowerCase().includes(menu.query));
    } else {
        // Discoverable openers so a partial "/inst" or "/vp" surfaces the command;
        // picking one inserts the trigger, which opens its picker (see apply()).
        const openers = [
            { key: 'open:install', label: 'Install software', hint: 'Pull an installer from the share', run: (e) => e.chain().focus().insertContent('/install ').run() },
            { key: 'open:vpn', label: 'VPN profile', hint: 'Pull a VPN config from the share', run: (e) => e.chain().focus().insertContent('/vpn ').run() },
            { key: 'open:mdm', label: 'MDM enrollment', hint: 'Enroll into Jamf, Intune, …', run: (e) => e.chain().focus().insertContent('/mdm ').run() },
            { key: 'open:form', label: 'Record form', hint: 'The task gets an add-record form', run: (e) => e.chain().focus().insertContent('/form ').run() },
        ];
        const all = [...SLASH, ...openers, ...refItems];
        items = menu ? all.filter((s) => s.label.toLowerCase().includes(menu.query) || (s.slug || '').includes(menu.query)) : [];
    }

    const apply = (item) => {
        if (!editor || !item) return;
        editor.chain().focus().deleteRange({ from: menu.from, to: menu.to }).run();
        item.run(editor);
        // Re-detect instead of just closing: an opener that inserted "/install " or
        // "/vpn " should immediately open its picker; anything else closes the menu.
        detectSlash(editor);
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

    // Keep the slash menu inside the viewport: cap its height (it scrolls internally)
    // and flip it above the caret when there isn't room below.
    const vh = typeof window !== 'undefined' ? window.innerHeight : 800;
    const spaceBelow = menu ? vh - menu.y : 0;
    const openUp = menu ? spaceBelow < 260 && (menu.yTop ?? menu.y) > spaceBelow : false;
    const menuStyle = menu ? {
        position: 'fixed', left: menu.x, zIndex: 60,
        maxHeight: Math.max(160, Math.min(320, openUp ? (menu.yTop ?? menu.y) - 12 : spaceBelow - 12)),
        ...(openUp ? { bottom: vh - (menu.yTop ?? menu.y) + 6 } : { top: menu.y + 4 }),
    } : null;

    return (
        <div className="relative">
            <EditorContent editor={editor} />
            {menu && items.length > 0 && (
                <div ref={menuRef} style={menuStyle}
                    className="w-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-xl py-1 text-sm">
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
