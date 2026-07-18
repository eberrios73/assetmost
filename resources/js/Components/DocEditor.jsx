import { useEditor, EditorContent, useEditorState } from '@tiptap/react';
import { Node, mergeAttributes } from '@tiptap/core';
import ListItem from '@tiptap/extension-list-item';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableHeader } from '@tiptap/extension-table-header';
import { TableCell } from '@tiptap/extension-table-cell';
import CodeBlock from '@tiptap/extension-code-block';
import { marked } from 'marked';
import { useEffect, useRef, useState } from 'react';

// Shared node-view chrome helpers (step cards + substep mini-cards).
const mkBtn = (label, title, fn) => {
    const b = document.createElement('button');
    b.type = 'button'; b.textContent = label; b.title = title;
    b.addEventListener('mousedown', (e) => e.preventDefault());
    b.addEventListener('click', fn);
    return b;
};
// Swap a node with its previous/next sibling (steps in the doc, substeps in a list).
const moveSibling = (editor, getPos, dir) => {
    const pos = getPos();
    const $pos = editor.state.doc.resolve(pos);
    const index = $pos.index();
    const parent = $pos.parent;
    if (dir < 0 && index === 0) return;
    if (dir > 0 && index >= parent.childCount - 1) return;
    const self = parent.child(index);
    const sib = parent.child(index + dir);
    const tr = editor.state.tr.delete(pos, pos + self.nodeSize);
    tr.insert(dir < 0 ? pos - sib.nodeSize : pos + sib.nodeSize, self);
    editor.view.dispatch(tr);
};

/**
 * Substeps get their own mini-card chrome (reorder, remove) — but ONLY inside a
 * step card; list items in plain docs render exactly as before (the wrapper is
 * display:contents, invisible to layout and never serialized).
 */
const SopListItem = ListItem.extend({
    addNodeView() {
        return ({ editor, getPos }) => {
            const li = document.createElement('li');
            const content = document.createElement('div');
            content.className = 'sop-sub-content';

            const $pos = editor.state.doc.resolve(getPos());
            let inStep = false;
            for (let d = $pos.depth; d > 0; d--) {
                if ($pos.node(d).type.name === 'sopStep') { inStep = true; break; }
            }
            if (!inStep) {
                li.appendChild(content);
                return { dom: li, contentDOM: content };
            }

            const chrome = document.createElement('div');
            chrome.className = 'sop-sub-chrome';
            chrome.contentEditable = 'false';
            const removeSub = () => {
                const pos = getPos();
                const $p = editor.state.doc.resolve(pos);
                const parent = $p.parent;
                if (parent.childCount === 1) {
                    // Last substep: take the empty list with it (an empty <ul> is invalid).
                    const pPos = $p.before($p.depth);
                    editor.chain().deleteRange({ from: pPos, to: pPos + parent.nodeSize }).run();
                } else {
                    const self = parent.child($p.index());
                    editor.chain().deleteRange({ from: pos, to: pos + self.nodeSize }).run();
                }
            };
            chrome.append(
                mkBtn('↑', 'move substep up', () => moveSibling(editor, getPos, -1)),
                mkBtn('↓', 'move substep down', () => moveSibling(editor, getPos, 1)),
                mkBtn('×', 'remove substep', removeSub),
            );
            li.append(chrome, content);
            return { dom: li, contentDOM: content };
        };
    },
});

/**
 * A workflow step as a CARD in the doc — the structured editor's model rendered
 * by the one editor. Serializes to <section data-sop-step> around the same
 * title + field table + substep list the parser already reads; the chrome
 * (number, reorder, collapse, add-substep, remove) is node-view UI, never part
 * of the saved document.
 */
const SopStep = Node.create({
    name: 'sopStep',
    group: 'block',
    content: 'block+',
    defining: true,
    parseHTML() { return [{ tag: 'section[data-sop-step]' }]; },
    renderHTML({ HTMLAttributes }) { return ['section', mergeAttributes(HTMLAttributes, { 'data-sop-step': '' }), 0]; },
    addNodeView() {
        return ({ node, editor, getPos }) => {
            const card = document.createElement('section');
            card.className = 'sop-step';

            const num = document.createElement('span');
            num.className = 'sop-step-num';
            num.contentEditable = 'false';

            const chrome = document.createElement('div');
            chrome.className = 'sop-step-chrome';
            chrome.contentEditable = 'false';

            let collapsed = false;
            chrome.append(
                mkBtn('↑', 'move step up', () => moveSibling(editor, getPos, -1)),
                mkBtn('↓', 'move step down', () => moveSibling(editor, getPos, 1)),
                mkBtn('≡', 'collapse / expand', () => { collapsed = !collapsed; card.classList.toggle('sop-collapsed', collapsed); }),
                mkBtn('⊞', 'add fields (Why / How / Done when / Record)', () => {
                    const pos = getPos();
                    const n = editor.state.doc.nodeAt(pos);
                    if (!n || !n.firstChild) return;
                    // Right after the title; skip if the card already has a table.
                    let hasTable = false;
                    n.forEach((c) => { if (c.type.name === 'table') hasTable = true; });
                    if (hasTable) return;
                    editor.chain().focus().insertContentAt(pos + 1 + n.firstChild.nodeSize,
                        '<table><tbody>' + fieldRows(['Why', 'How', 'Done when', 'Record']) + '</tbody></table>').run();
                }),
                mkBtn('↳+', 'add substep', () => {
                    const pos = getPos();
                    const n = editor.state.doc.nodeAt(pos);
                    if (!n) return;
                    const end = pos + n.nodeSize - 1;
                    if (n.lastChild && n.lastChild.type.name === 'bulletList') {
                        editor.chain().focus().insertContentAt(end - 1, '<li><p></p></li>').run();
                    } else {
                        editor.chain().focus().insertContentAt(end, '<ul><li><p></p></li></ul>').run();
                    }
                }),
                mkBtn('×', 'remove step', () => {
                    const pos = getPos();
                    const n = editor.state.doc.nodeAt(pos);
                    if (n) editor.chain().deleteRange({ from: pos, to: pos + n.nodeSize }).run();
                }),
            );

            const content = document.createElement('div');
            content.className = 'sop-step-content';
            card.append(num, chrome, content);
            return {
                dom: card,
                contentDOM: content,
                update: (updated) => updated.type.name === 'sopStep',
            };
        };
    },
});

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

// Insert a command token as a SUBSTEP: a bullet under the current step (top-level
// bullets parse as subtasks). Already inside a bullet? The text lands right there.
// Inside a step card — including its field table — the bullet appends to the CARD's
// substep list, never into a table cell.
//
// Everything is POSITION-anchored and runs in one transaction: deleting the typed
// token used to move the selection out of the substep, so a selection-based insert
// landed outside the card. Text inserts as a text NODE (a plain string starting
// with "/" is not parsed as HTML, and escaping it double-escapes).
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const stepAncestor = (e) => {
    const { $from } = e.state.selection;
    for (let d = $from.depth; d > 0; d--) {
        if ($from.node(d).type.name === 'sopStep') return { node: $from.node(d), pos: $from.before(d) };
    }
    return null;
};
const asSubstep = (e, range, text) => {
    const $pos = e.state.doc.resolve(range.from);
    let inLi = false;
    let step = null;
    for (let d = $pos.depth; d > 0; d--) {
        const n = $pos.node(d);
        if (n.type.name === 'listItem') inLi = true;
        if (n.type.name === 'sopStep') { step = { node: n, pos: $pos.before(d) }; break; }
    }
    const shift = range.to - range.from;
    const del = e.chain().focus().deleteRange(range);
    if (inLi) {
        return del.insertContentAt(range.from, { type: 'text', text }).run();
    }
    if (step) {
        // The card's end, in post-delete coordinates (the range sits inside the card).
        const end = step.pos + step.node.nodeSize - 1 - shift;
        const hasUl = step.node.lastChild && step.node.lastChild.type.name === 'bulletList';
        return hasUl
            ? del.insertContentAt(end - 1, `<li><p>${esc(text)}</p></li>`).run()
            : del.insertContentAt(end, `<ul><li><p>${esc(text)}</p></li></ul>`).run();
    }
    return del.insertContentAt(range.from, `<ul><li><p>${esc(text)}</p></li></ul>`).run();
};

// The /step scaffold: a LEAN step card — steps are just steps (actions); add a
// field row (Done when etc.) or substeps only where a step earns them.
const STEP_SCAFFOLD = '<section data-sop-step><p><strong>New step</strong></p><p></p></section><p></p>';
const fieldRows = (labels) => labels.map((l) => `<tr><td><p><strong>${l}:</strong></p></td><td><p></p></td></tr>`).join('');
// The /sop scaffold: the document-level header table of a formal SOP — purpose,
// approach, tools, safety, governance. Every row optional; remove what you don't need.
const SOP_SCAFFOLD = '<table><tbody>'
    + fieldRows(['Why', 'How', 'Tools and Materials', 'Safety Precautions', 'Owner', 'Version'])
    + '</tbody></table><p></p>';

// Tables never nest: inserting one from inside a table lands AFTER that table.
const afterTablePos = (e) => {
    const { $from } = e.state.selection;
    for (let d = $from.depth; d > 0; d--) {
        if ($from.node(d).type.name === 'table') return $from.after(d);
    }
    return null;
};
const insertBlockSafe = (e, html) => {
    const after = afterTablePos(e);
    return after !== null
        ? e.chain().focus().insertContentAt(after, html).run()
        : e.chain().focus().insertContent(html).run();
};

const SLASH = [
    // /step inside an existing card inserts the new card AFTER it (never nested).
    { key: 'step', label: 'Step', hint: 'New SOP step — a lean action card; ⊞ adds fields, ↳+ substeps', run: (e) => {
        const step = stepAncestor(e);
        return step
            ? e.chain().focus().insertContentAt(step.pos + step.node.nodeSize, STEP_SCAFFOLD).run()
            : e.chain().focus().insertContent(STEP_SCAFFOLD).run();
    } },
    { key: 'sop', label: 'SOP header', alias: 'table header sop', hint: 'Top table: Why/How, Tools, Safety, Owner… (rows removable)', run: (e) => insertBlockSafe(e, SOP_SCAFFOLD) },
    { key: 'fields', label: 'Step fields table', alias: 'table why how done record fields', hint: '2-column Why / How / Done when / Record table', run: (e) => insertBlockSafe(e, '<table><tbody>' + fieldRows(['Why', 'How', 'Done when', 'Record']) + '</tbody></table>') },
    { key: 'p', label: 'Text', hint: 'Plain paragraph', run: (e) => e.chain().focus().setParagraph().run() },
    { key: 'h1', label: 'Heading 1', hint: 'Big section heading', run: (e) => e.chain().focus().toggleHeading({ level: 1 }).run() },
    { key: 'h2', label: 'Heading 2', hint: 'Medium heading', run: (e) => e.chain().focus().toggleHeading({ level: 2 }).run() },
    { key: 'h3', label: 'Heading 3', hint: 'Small heading', run: (e) => e.chain().focus().toggleHeading({ level: 3 }).run() },
    { key: 'ul', label: 'Bulleted list', hint: 'Simple bullets', run: (e) => e.chain().focus().toggleBulletList().run() },
    { key: 'ol', label: 'Numbered list', hint: 'Ordered steps', run: (e) => e.chain().focus().toggleOrderedList().run() },
    { key: 'quote', label: 'Quote', hint: 'Callout / block quote', run: (e) => e.chain().focus().toggleBlockquote().run() },
    { key: 'code', label: 'Code block', hint: 'Monospace snippet', run: (e) => e.chain().focus().toggleCodeBlock().run() },
    { key: 'table', label: 'Table', hint: '3×3 with header row', run: (e) => { const after = afterTablePos(e); const c = e.chain().focus(); return (after !== null ? c.setTextSelection(after) : c).insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(); } },
    { key: 'hr', label: 'Divider', hint: 'Horizontal rule', run: (e) => e.chain().focus().setHorizontalRule().run() },
];

/** Notion/Docmost-style canvas: rich text + "/" slash menu. Autosaves HTML (debounced). */
export default function DocEditor({ pageId, initialBody, onSave }) {
    const [menu, setMenu] = useState(null); // { query, from, x, y, index }
    const [refs, setRefs] = useState([]);         // runbook references: [{slug, name}]
    const [installers, setInstallers] = useState([]);   // indexed installers share
    const [snippets, setSnippets] = useState([]);       // the commands registry
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
        fetch('/data/snippets', { headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then((d) => setSnippets(Array.isArray(d) ? d : [])).catch(() => {});
    }, []);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ codeBlock: false, listItem: false }),
            SopListItem,
            CodeBlockWithCopy,
            SopStep,
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
                self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/install  `) }));
        // Works before the share is indexed: keep whatever was typed as the reference.
        if (nameQ) picks.push({ key: 'inst:free', label: `Use "${nameQ}"`, hint: 'insert as typed',
            self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/install  `) });
        items = picks;
    } else if (menu?.mode === 'vpn') {
        const picks = installers
            .filter((i) => VPN_RE.test(i.name))                                    // VPN profiles only
            .filter((i) => !menu.query || i.name.toLowerCase().includes(menu.query))
            .slice(0, 8)
            .map((i) => ({ key: `vpn:${i.id}`, label: i.name.replace(VPN_RE, ''), hint: 'VPN profile — download + install',
                self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/vpn  `) }));
        if (menu.query) picks.push({ key: 'vpn:free', label: `Use "${menu.query}"`, hint: 'insert as typed',
            self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/vpn  `) });
        items = picks;
    } else if (menu?.mode === 'mdm') {
        // A fixed list, not the share — /mdm names the MDM the bootstrap script enrolls into.
        const MDM = ['Jamf', 'Intune', 'Kandji', 'Mosyle', 'Addigy', 'Workspace ONE'];
        const picks = MDM.filter((n) => !menu.query || n.toLowerCase().includes(menu.query))
            .map((n) => ({ key: `mdm:${n}`, label: n, hint: 'Enroll into this MDM (read from the SOP)',
                self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/mdm  `) }));
        if (menu.query) picks.push({ key: 'mdm:free', label: `Use "${menu.query}"`, hint: 'insert as typed',
            self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/mdm  `) });
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
            self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/form   `),
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
        // Registry commands: everything in Docs > Commands is a slash command. The
        // hint shows its declared params; args are typed after it on the substep line.
        const snippetItems = snippets.filter((s) => s.active).map((s) => ({
            key: `snip:${s.command}`,
            label: `/${s.command}${s.params ? ' ' + s.params.split(',').map((p) => p.trim()).join(' ') : ''}`,
            hint: s.label || 'SOP command',
            alias: s.command,
            self: true,
            run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/${s.command} `),
        }));
        const all = [...SLASH, ...openers, ...refItems, ...snippetItems];
        items = menu ? all.filter((s) => s.label.toLowerCase().includes(menu.query) || (s.alias || '').includes(menu.query) || (s.slug || '').includes(menu.query)) : [];
    }

    const apply = (item) => {
        if (!editor || !item) return;
        // Token picks (self: true) delete their own typed range and insert at an
        // anchored position in ONE transaction — then the menu closes for good.
        if (item.self) {
            item.run(editor);
            setMenu(null);
            return;
        }
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

    // Row/column controls whenever the cursor is inside a table — pinned to THAT
    // table's top-right corner (measured after render), not a detached bar.
    const inTable = useEditorState({
        editor,
        selector: (ctx) => !!ctx.editor && ctx.editor.isActive('table'),
    });
    const [tblPos, setTblPos] = useState(null);
    const wrapRef = useRef(null);
    useEffect(() => {
        if (!inTable || !editor || !wrapRef.current) { setTblPos(null); return; }
        try {
            const dom = editor.view.domAtPos(editor.state.selection.from).node;
            const base = dom.nodeType === 1 ? dom : dom.parentElement;
            const el = base?.closest('table');
            if (!el) { setTblPos(null); return; }
            const t = el.getBoundingClientRect();
            const row = base?.closest('tr')?.getBoundingClientRect();
            const cell = base?.closest('td,th')?.getBoundingClientRect();
            const wr = wrapRef.current.getBoundingClientRect();
            const rel = (r) => r && { top: r.top - wr.top, left: r.left - wr.left, right: r.right - wr.left, bottom: r.bottom - wr.top, width: r.width, height: r.height };
            setTblPos({ table: rel(t), row: rel(row), cell: rel(cell) });
        } catch { setTblPos(null); }
    }, [inTable, editor, editor && editor.state.selection.from]);

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

    // Numbers-style edge handles: small round buttons ON the table frame.
    const handle = (style, title, label, fn, danger = false) => (
        <button type="button" title={title} onMouseDown={(e) => e.preventDefault()} onClick={fn}
            style={{ position: 'absolute', zIndex: 30, ...style }}
            className={`flex h-5 w-5 items-center justify-center rounded-full border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[13px] leading-none text-gray-400 shadow-sm ${danger ? 'hover:text-red-600 hover:border-red-300' : 'hover:text-blue-600 hover:border-blue-300'}`}>
            {label}
        </button>
    );

    return (
        <div className="relative" ref={wrapRef}>
            {inTable && editor && tblPos?.table && (
                <>
                    {/* + centered ON the bottom border: add row. + on the right border: add column. */}
                    {handle({ top: tblPos.table.bottom - 10, left: tblPos.table.left + tblPos.table.width / 2 - 10 },
                        'add a row', '+', () => editor.chain().focus().addRowAfter().run())}
                    {handle({ top: tblPos.table.top + tblPos.table.height / 2 - 10, left: tblPos.table.right - 10 },
                        'add a column', '+', () => editor.chain().focus().addColumnAfter().run())}
                    {/* − centered ON the border too: left border at the current row, top border at the current column. */}
                    {tblPos.row && handle({ top: tblPos.row.top + tblPos.row.height / 2 - 10, left: tblPos.table.left - 10 },
                        'delete this row', '−', () => editor.chain().focus().deleteRow().run(), true)}
                    {tblPos.cell && handle({ top: tblPos.table.top - 10, left: tblPos.cell.left + tblPos.cell.width / 2 - 10 },
                        'delete this column', '−', () => editor.chain().focus().deleteColumn().run(), true)}
                    {/* the table handle: top-RIGHT corner, removes the table */}
                    {handle({ top: tblPos.table.top - 10, left: tblPos.table.right - 10 },
                        'delete the whole table', '×', () => editor.chain().focus().deleteTable().run(), true)}
                </>
            )}
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
