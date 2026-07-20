import { useEditor, EditorContent, useEditorState } from '@tiptap/react';
import { Node, mergeAttributes, Extension } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
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
import { openRecordForm } from '@/lib/formBus';

// The SOP is the workflow: a substep carrying a /form token shows its live
// button, which summons the ONE app-wide record drawer (GlobalFormDrawer)
// with this page's company as context; the saved record flows back here.
const parseFormToken = (text) => {
    const m = /\/form\s+(?:(new|edit)\s+)?(device|person|account|location)/i.exec(text || '');
    return m ? { mode: (m[1] || 'new').toLowerCase(), kind: m[2].toLowerCase() } : null;
};

// --- command pills ----------------------------------------------------------
// Recognized slash commands render as PILLS — visible proof the token parsed
// and will resolve at generation. The registry arrives async: setKnownCmds()
// swaps the set and the editor nudges itself to re-decorate.
let KNOWN_CMDS = new Set(['install', 'vpn', 'mdm', 'form']);
const setKnownCmds = (names) => {
    KNOWN_CMDS = new Set(['install', 'vpn', 'mdm', 'form', ...names.filter(Boolean).map((n) => n.toLowerCase())]);
};

/** Same-named commands: the company row overrides the shipped one, blank
 *  fields falling back — mirrors the generator, so the menu shows ONE entry
 *  per command and it's the one that actually runs. */
const mergeSnippets = (list) => {
    const byCmd = new Map();
    for (const s of list) {
        const k = s.command.toLowerCase();
        const prev = byCmd.get(k);
        if (!prev) { byCmd.set(k, s); continue; }
        const company = s.shipped ? prev : s;
        const global = s.shipped ? s : prev;
        byCmd.set(k, {
            ...company,
            label: company.label || global.label,
            params: company.params || global.params,
            mac_script: company.mac_script || global.mac_script,
            windows_script: company.windows_script || global.windows_script,
            linux_script: company.linux_script || global.linux_script,
        });
    }
    return [...byCmd.values()];
};

const CmdPills = Extension.create({
    name: 'cmdPills',
    addProseMirrorPlugins() {
        return [new Plugin({
            props: {
                decorations(state) {
                    const decos = [];
                    state.doc.descendants((node, pos) => {
                        if (!node.isTextblock) return true;
                        // textBetween with 1-char leaf placeholders keeps offsets 1:1
                        // with doc positions (textContent would drift past hard breaks).
                        const text = node.textBetween(0, node.content.size, '￼', '￼');
                        const re = /\/([a-zA-Z][\w-]*)/g;
                        let m;
                        while ((m = re.exec(text))) {
                            // Path segments (/installs/mac, http://…) stay plain: a pill
                            // needs start-or-whitespace before and a break after.
                            if (m.index > 0 && !/\s/.test(text[m.index - 1])) continue;
                            const next = text[m.index + m[0].length];
                            if (next && !/\s/.test(next)) continue;
                            if (!KNOWN_CMDS.has(m[1].toLowerCase())) continue;
                            decos.push(Decoration.inline(pos + 1 + m.index, pos + 1 + m.index + m[0].length, { class: 'sop-cmd-pill' }));
                        }
                        return false;
                    });
                    return DecorationSet.create(state.doc, decos);
                },
            },
        })];
    },
});

// Two back-to-back substep lists in one card read as one list — make the
// document agree: whenever an edit leaves a step card with directly-adjacent
// bulletLists, join them (re-runs until stable; a paragraph between lists is
// content and blocks the join).
const JoinSubLists = Extension.create({
    name: 'joinSubLists',
    addProseMirrorPlugins() {
        return [new Plugin({
            appendTransaction(trs, oldState, newState) {
                if (!trs.some((t) => t.docChanged)) return null;
                let tr = null;
                newState.doc.descendants((node, pos) => {
                    if (tr || node.type.name !== 'sopStep') return !tr;
                    let child = pos + 1;
                    let prevWasUl = false;
                    node.forEach((c) => {
                        if (!tr && c.type.name === 'bulletList' && prevWasUl) {
                            try { tr = newState.tr.join(child); } catch { /* not joinable */ }
                        }
                        prevWasUl = c.type.name === 'bulletList';
                        child += c.nodeSize;
                    });
                    return false;
                });
                return tr;
            },
        })];
    },
});

// --- drag & drop for steps and substeps ------------------------------------
// POINTER-based, not HTML5 drag-and-drop: inside contentEditable the browser's
// native text-drag competes with dnd and ProseMirror swallows the mousedown,
// so the gesture never starts. The grip runs its own session instead:
// mousedown → hit-test what's under the cursor → highlight → mouseup drops.
// getPos closures stay valid, so positions are read AT DROP TIME; the move is
// one transaction (delete + mapped insert). A substep that is its list's only
// item takes the empty list with it.
let sopDrag = null;   // { type: 'step'|'sub', getPos, editor }

/** What's under the cursor: a substep (li) or a step card. The event's own
 *  target is the browser's hit-test result — more reliable than a second
 *  elementFromPoint pass. */
const gripTarget = (editor, under) => {
    if (!(under instanceof Element)) return null;
    const card = under.closest('section.sop-step');
    if (!card || !editor.view.dom.contains(card)) return null;
    const li = under.closest('li');
    return li && card.contains(li) ? { kind: 'li', el: li, card } : { kind: 'card', el: card, card };
};

/** Node views register their dom → getPos here, so a drop target's position
 *  comes straight from its own node view. (posAtDOM is NOT usable at drop
 *  time: the hover-highlight class mutations mark the view dirty between real
 *  events and it returns -1.) */
const GRIP_POS = new WeakMap();
const pmAt = (editor, el) => {
    const getPos = GRIP_POS.get(el);
    const pos = typeof getPos === 'function' ? getPos() : null;
    if (pos == null) return null;
    const node = editor.state.doc.nodeAt(pos);
    return node ? { node, pos } : null;
};

/** Perform the drop: substep after a substep, substep adopted by a card
 *  (into its list, created if missing), or step after a card. */
const dropGrip = (target) => {
    if (!sopDrag || !target) return;
    const { editor, type } = sopDrag;
    if (type === 'sub' && target.kind === 'li') {
        const tgt = pmAt(editor, target.el);
        if (tgt) moveDragged(editor, tgt.pos + tgt.node.nodeSize);
    } else if (type === 'sub') {
        const tgt = pmAt(editor, target.card);
        if (tgt) {
            const slot = subListSlot(tgt.node, tgt.pos);
            moveDragged(editor, slot.at, !slot.hasList);
        }
    } else {
        const tgt = pmAt(editor, target.card);
        if (tgt) moveDragged(editor, tgt.pos + tgt.node.nodeSize);
    }
};

const mkGrip = (type, getPos, editor, srcEl) => {
    const g = document.createElement('span');
    g.className = 'sop-grip';
    g.textContent = '⠿';
    g.title = 'drag to move';
    g.contentEditable = 'false';
    g.addEventListener('mousedown', (e) => {
        e.preventDefault();       // no text selection; ProseMirror never sees it
        e.stopPropagation();
        sopDrag = { type, getPos, editor };
        let target = null;
        srcEl.classList.add('sop-drag-src');
        document.body.classList.add('sop-dragging');
        const clearHi = () => document.querySelectorAll('.sop-drop').forEach((el) => el.classList.remove('sop-drop'));
        const move = (ev) => {
            clearHi();
            target = gripTarget(editor, ev.target instanceof Element ? ev.target : document.elementFromPoint(ev.clientX, ev.clientY));
            const self = target && (type === 'step' ? target.card === srcEl : (target.kind === 'li' && target.el === srcEl));
            if (self) target = null;
            if (target) (type === 'step' ? target.card : target.el).classList.add('sop-drop');
        };
        const up = () => {
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            document.body.classList.remove('sop-dragging');
            srcEl.classList.remove('sop-drag-src');
            clearHi();
            if (target) dropGrip(target);
            sopDrag = null;
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    });
    return g;
};

const moveDragged = (editor, insertPosPreDelete, wrapInList = false) => {
    if (!sopDrag) return;
    const srcPos = sopDrag.getPos();
    const state = editor.state;
    const srcNode = state.doc.nodeAt(srcPos);
    if (!srcNode) { sopDrag = null; return; }
    const $src = state.doc.resolve(srcPos);
    let delFrom = srcPos;
    let delTo = srcPos + srcNode.nodeSize;
    if (sopDrag.type === 'sub' && $src.parent.type.name === 'bulletList' && $src.parent.childCount === 1) {
        delFrom = $src.before($src.depth);
        delTo = delFrom + $src.parent.nodeSize;
    }
    if (insertPosPreDelete >= delFrom && insertPosPreDelete <= delTo) { sopDrag = null; return; }
    let tr = state.tr.delete(delFrom, delTo);
    const payload = wrapInList ? editor.schema.nodes.bulletList.create(null, srcNode) : srcNode;
    tr = tr.insert(tr.mapping.map(insertPosPreDelete), payload);
    editor.view.dispatch(tr);
    sopDrag = null;
};

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
        return ({ node, editor, getPos }) => {
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

            GRIP_POS.set(li, getPos);
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
            // A /form token in this substep gets its live button — clicking opens
            // the record form right here on the SOP.
            const formBtn = document.createElement('button');
            formBtn.type = 'button';
            formBtn.className = 'sop-form-btn';
            formBtn.addEventListener('mousedown', (e) => e.preventDefault());
            formBtn.addEventListener('click', () => {
                const f = parseFormToken(formBtn.dataset.token || '');
                if (f) li.dispatchEvent(new CustomEvent('sop-form-open', { detail: f, bubbles: true }));
            });
            const syncForm = (n) => {
                const f = parseFormToken(n.textContent);
                formBtn.dataset.token = n.textContent;
                formBtn.style.display = f ? '' : 'none';
                if (f) formBtn.textContent = `${f.mode === 'edit' ? 'Edit' : 'Add'} ${f.kind}`;
            };
            syncForm(node);

            chrome.append(
                formBtn,
                mkGrip('sub', getPos, editor, li),
                mkBtn('↑', 'move substep up', () => moveSibling(editor, getPos, -1)),
                mkBtn('↓', 'move substep down', () => moveSibling(editor, getPos, 1)),
                mkBtn('×', 'remove substep', removeSub),
            );
            li.append(chrome, content);
            return {
                dom: li,
                contentDOM: content,
                // Chrome/highlight class changes must not dirty the view.
                ignoreMutation: (m) => m.type === 'attributes' || chrome.contains(m.target),
                update: (updated) => {
                    if (updated.type.name !== 'listItem') return false;
                    syncForm(updated);
                    return true;
                },
            };
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
            GRIP_POS.set(card, getPos);

            const num = document.createElement('span');
            num.className = 'sop-step-num';
            num.contentEditable = 'false';

            const chrome = document.createElement('div');
            chrome.className = 'sop-step-chrome';
            chrome.contentEditable = 'false';

            let collapsed = false;
            chrome.append(
                mkGrip('step', getPos, editor, card),
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
                    const slot = subListSlot(n, pos);
                    editor.chain().focus().insertContentAt(slot.at,
                        slot.hasList ? '<li><p></p></li>' : '<ul><li><p></p></li></ul>').run();
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
                // Chrome/highlight class changes must not dirty the view.
                ignoreMutation: (m) => m.type === 'attributes' || chrome.contains(m.target),
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
// The card's substep list is its LAST bulletList child — not necessarily the
// last child (a trailing paragraph must not spawn a second list). Returns the
// li-insertion point inside it, or the create-a-list point at the card's end.
const subListSlot = (node, pos) => {
    let ulStart = null, ulNode = null;
    let child = pos + 1;
    node.forEach((c) => {
        if (c.type.name === 'bulletList') { ulStart = child; ulNode = c; }
        child += c.nodeSize;
    });
    return ulNode
        ? { at: ulStart + ulNode.nodeSize - 1, hasList: true }
        : { at: pos + node.nodeSize - 1, hasList: false };
};
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
        // Post-delete coordinates: the slot only shifts if the typed token sat
        // before it (title or a paragraph above the list).
        const slot = subListSlot(step.node, step.pos);
        const at = slot.at - (range.to <= slot.at ? shift : 0);
        return slot.hasList
            ? del.insertContentAt(at, `<li><p>${esc(text)}</p></li>`).run()
            : del.insertContentAt(at, `<ul><li><p>${esc(text)}</p></li></ul>`).run();
    }
    return del.insertContentAt(range.from, `<ul><li><p>${esc(text)}</p></li></ul>`).run();
};

// The /step scaffold: a LEAN step card — steps are just steps (actions); add a
// field row (Done when etc.) or substeps only where a step earns them.
const STEP_SCAFFOLD = '<section data-sop-step><p><strong>New step</strong></p><p></p></section><p></p>';
const fieldRows = (labels) => labels.map((l) => `<tr><td><p><strong>${l}:</strong></p></td><td><p></p></td></tr>`).join('');

/**
 * /sop inserts OR REFRESHES the SOP header: if the doc already has a header
 * table (the first top-level table before any heading/step), it's rebuilt in
 * place — missing standard rows added (OS prefilled when known), existing
 * values and custom rows kept. The command IS how a header catches up when
 * the vocabulary grows.
 */
const SOP_TRIO = ['OS', 'Owner', 'Version', 'Status'];             // one compact LAST row
const SOP_SINGLE_ROWS = ['Why', 'How', 'Scope', 'Tools and Materials', 'Safety Precautions'];
const OS_CHOICES = ['macOS', 'Windows', 'Linux', 'iOS', 'Android'];
const refreshSopHeader = (e, osDefault = '', ownerDefault = '') => {
    const doc = e.state.doc;
    let tablePos = null;
    let tableNode = null;
    let stop = false;
    doc.forEach((child, offset) => {
        if (stop || tableNode) return;
        if (child.type.name === 'heading' || child.type.name === 'sopStep') { stop = true; return; }
        if (child.type.name === 'table') { tablePos = offset; tableNode = child; }
    });

    // Read label/value PAIRS from every row (handles single- and multi-pair rows).
    const existing = new Map();
    if (tableNode) {
        tableNode.forEach((row) => {
            for (let i = 0; i + 1 < row.childCount; i += 2) {
                const label = row.child(i).textContent.replace(/:\s*$/, '').trim();
                if (!label) continue;
                const lines = [];
                row.child(i + 1).forEach((p) => { if (p.textContent.trim() !== '') lines.push(p.textContent); });
                existing.set(label.toLowerCase(), { label, lines });
            }
        });
    }

    const cellHtml = (lines) => (lines.length ? lines.map((l) => `<p>${esc(l)}</p>`).join('') : '<p></p>');
    const used = new Set();
    const lookup = (label, def = []) => {
        used.add(label.toLowerCase());
        const ex = existing.get(label.toLowerCase());
        let lines = ex ? ex.lines : [];
        if (!lines.length && !ex) lines = def;
        if (label === 'OS' && !lines.length && osDefault) lines = [osDefault];
        if (label === 'Owner' && !lines.length && ownerDefault) lines = [ownerDefault];
        return lines;
    };
    // Content rows first (values span the width); the OS · Owner · Version trio
    // renders LAST. The trio is computed first so its labels are marked used
    // before the custom-row sweep.
    let trioCells = '';
    for (const label of SOP_TRIO) {
        const lines = lookup(label, label === 'Version' ? ['1.0'] : label === 'Status' ? ['Draft'] : []);
        trioCells += `<td><p><strong>${esc(label)}:</strong></p></td><td>${cellHtml(lines)}</td>`;
    }
    let rows = '';
    for (const label of SOP_SINGLE_ROWS) {
        const lines = lookup(label);
        rows += `<tr><td><p><strong>${esc(label)}:</strong></p></td><td colspan="7">${cellHtml(lines)}</td></tr>`;
    }
    for (const [key, ex] of existing) {
        if (!used.has(key)) rows += `<tr><td><p><strong>${esc(ex.label)}:</strong></p></td><td colspan="7">${cellHtml(ex.lines)}</td></tr>`;
    }
    rows += `<tr>${trioCells}</tr>`;
    const html = `<table><tbody>${rows}</tbody></table>`;

    return tableNode
        ? e.chain().focus().deleteRange({ from: tablePos, to: tablePos + tableNode.nodeSize }).insertContentAt(tablePos, html).run()
        : e.chain().focus().insertContentAt(0, html).run();
};

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
    // Opens the cheatsheet panel instead of inserting anything (handled in apply).
    { key: 'help', label: 'Help', hint: 'The SOP cheatsheet — commands, cards, header rows', help: true, run: () => {} },
    // /step inside an existing card inserts the new card AFTER it (never nested).
    { key: 'step', label: 'Step', hint: 'New SOP step — a lean action card; ⊞ adds fields, ↳+ substeps', run: (e) => {
        const step = stepAncestor(e);
        return step
            ? e.chain().focus().insertContentAt(step.pos + step.node.nodeSize, STEP_SCAFFOLD).run()
            : e.chain().focus().insertContent(STEP_SCAFFOLD).run();
    } },
    // run is overridden per-render so it can carry the page's OS default.
    { key: 'sop', label: 'SOP header', alias: 'table header sop refresh', hint: 'Insert or refresh the top table — adds missing rows, keeps your values', run: (e) => refreshSopHeader(e) },
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
// Real extensions exported so the headless harness tests the SHIPPED node
// views (chrome, drag & drop, pills), not a re-implementation.
export { SopStep, SopListItem, CmdPills, JoinSubLists, setKnownCmds, mergeSnippets, asSubstep };

export default function DocEditor({ pageId, initialBody, onSave, osDefault = '', ownerDefault = '', companyId = null }) {
    const [menu, setMenu] = useState(null); // { query, from, x, y, index }
    const [helpOpen, setHelpOpen] = useState(false);
    const [flash, setFlash] = useState(null);       // "✓ device recorded: …" after a /form save
    const [editedBy, setEditedBy] = useState(null); // { user, you } — someone ELSE has this doc open
    const editorIdRef = useRef(Math.random().toString(36).slice(2));
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

    // One menu entry per command (company overrides shipped) — also the pill set.
    const activeSnippets = mergeSnippets(snippets.filter((s) => s.active));
    useEffect(() => {
        setKnownCmds([...activeSnippets.map((s) => s.command), ...refs.map((r) => r.slug)]);
        if (editor && !editor.isDestroyed) editor.view.dispatch(editor.state.tr);
    }, [snippets, refs]);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ codeBlock: false, listItem: false }),
            SopListItem,
            CodeBlockWithCopy,
            SopStep,
            CmdPills,
            JoinSubLists,
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
        // The OS value cell (right after an "OS:" label) is a SELECTOR, not free
        // text — entering it pops the canonical OS list.
        for (let d = $from.depth; d > 0; d--) {
            const n = $from.node(d);
            if (n.type.name === 'tableCell' || n.type.name === 'tableHeader') {
                const row = $from.node(d - 1);
                if (row && row.type.name === 'tableRow') {
                    const idx = $from.index(d - 1);
                    if (idx % 2 === 1) {
                        const label = row.child(idx - 1).textContent.replace(/:\s*$/, '').trim().toLowerCase();
                        if (label === 'os' || label === 'platform') {
                            const coords = ed.view.coordsAtPos($from.pos);
                            return setMenu({ mode: 'os', query: n.textContent.trim().toLowerCase(),
                                from: $from.start(d), to: $from.end(d),
                                x: coords.left, y: coords.bottom, yTop: coords.top, index: 0 });
                        }
                    }
                }
                break;
            }
        }
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
                self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/install ${i.name} `) }));
        // Works before the share is indexed: keep whatever was typed as the reference.
        if (nameQ) picks.push({ key: 'inst:free', label: `Use "${nameQ}"`, hint: 'insert as typed',
            self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/install ${menu.query} `) });
        items = picks;
    } else if (menu?.mode === 'vpn') {
        const picks = installers
            .filter((i) => VPN_RE.test(i.name))                                    // VPN profiles only
            .filter((i) => !menu.query || i.name.toLowerCase().includes(menu.query))
            .slice(0, 8)
            .map((i) => ({ key: `vpn:${i.id}`, label: i.name.replace(VPN_RE, ''), hint: 'VPN profile — download + install',
                self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/vpn ${i.name} `) }));
        if (menu.query) picks.push({ key: 'vpn:free', label: `Use "${menu.query}"`, hint: 'insert as typed',
            self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/vpn ${menu.query} `) });
        items = picks;
    } else if (menu?.mode === 'os') {
        // ALWAYS all five — never filtered by the current value, so converting a
        // Mac SOP to Linux is one click. Canonical values only.
        items = OS_CHOICES.map((o) => ({
            key: `os:${o}`, label: o,
            hint: o.toLowerCase() === menu.query ? 'current' : "Set this SOP's OS",
            self: true,
            run: (e) => e.chain().focus().deleteRange({ from: menu.from, to: menu.to }).insertContentAt(menu.from, `<p>${o}</p>`).run(),
        }));
    } else if (menu?.mode === 'mdm') {
        // A fixed list, not the share — /mdm names the MDM the bootstrap script
        // enrolls into. Picking one opens stage 2: HOW the device enrolls —
        // auto (bought on the business account, sits in ABM, zero-touch ADE)
        // vs manual (retail-bought, no ABM record, profile approved by hand).
        const MDM = ['Jamf', 'Intune', 'Kandji', 'Mosyle', 'Addigy', 'Workspace ONE'];
        const staged = menu.query.match(/^(\S+)\s+(.*)$/);
        if (staged) {
            const name = staged[1];
            items = [
                { key: 'mdmmode:auto', label: 'Automated (ABM / zero-touch)',
                  hint: 'Bought through the business account — the device is in Apple Business Manager and enrolls itself',
                  self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/mdm ${name} auto `) },
                { key: 'mdmmode:manual', label: 'Manual (retail-bought)',
                  hint: 'No ABM record — the enrollment profile is staged and approved by hand',
                  self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/mdm ${name} manual `) },
            ].filter((it) => !staged[2] || it.label.toLowerCase().includes(staged[2]));
        } else {
            const picks = MDM.filter((n) => !menu.query || n.toLowerCase().includes(menu.query))
                .map((n) => ({ key: `mdm:${n}`, label: n, hint: 'Enroll into this MDM — next: automated (ABM) or manual (retail)',
                    self: true, more: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/mdm ${n} `) }));
            if (menu.query) picks.push({ key: 'mdm:free', label: `Use "${menu.query}"`, hint: 'insert as typed',
                self: true, more: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/mdm ${menu.query} `) });
            items = picks;
        }
    } else if (menu?.mode === 'form') {
        // /form <new|edit> <kind>: the step's generated task carries the record form —
        // new creates, edit picks an existing record and updates it. Both in the
        // workflow's company. The Record field made executable.
        const KINDS = ['device', 'person', 'account', 'location'];
        items = ['new', 'edit'].flatMap((mode) => KINDS.map((k) => ({
            key: `form:${mode}:${k}`,
            label: `${mode === 'new' ? 'New' : 'Edit'} ${k}`,
            hint: mode === 'new' ? `The task gets an "Add ${k}" form` : `The task gets a pick-and-edit ${k} form`,
            self: true, run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/form ${mode} ${k} `),
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
        const snippetItems = activeSnippets.map((s) => ({
            key: `snip:${s.command}`,
            label: `/${s.command}${s.params ? ' ' + s.params.split(',').map((p) => p.trim()).join(' ') : ''}`,
            hint: s.label || 'SOP command',
            alias: s.command,
            self: true,
            run: (e) => asSubstep(e, { from: menu.from, to: menu.to }, `/${s.command} `),
        }));
        const all = [...SLASH, ...openers, ...refItems, ...snippetItems]
            .map((it) => (it.key === 'sop' ? { ...it, run: (e) => refreshSopHeader(e, osDefault, ownerDefault) } : it));
        items = menu ? all.filter((s) => s.label.toLowerCase().includes(menu.query) || (s.alias || '').includes(menu.query) || (s.slug || '').includes(menu.query)) : [];
    }

    const apply = (item) => {
        if (!editor || !item) return;
        // /help opens the cheatsheet; the typed token is removed, nothing inserted.
        if (item.help) {
            editor.chain().focus().deleteRange({ from: menu.from, to: menu.to }).run();
            setMenu(null);
            setHelpOpen(true);
            return;
        }
        // Token picks (self: true) delete their own typed range and insert at an
        // anchored position in ONE transaction — then the menu closes for good.
        // `more` picks have a second stage: re-detect so it opens right away.
        if (item.self) {
            item.run(editor);
            if (item.more) detectSlash(editor);
            else setMenu(null);
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

    // Editing presence: heartbeat while this editor is mounted; show a lock
    // when ANY other editor has the same doc open (another person, or you in
    // another window — the way deleted steps used to resurrect).
    useEffect(() => {
        if (!pageId) return;
        let stop = false;
        const beat = (release = false) => {
            const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
            return fetch(`/data/docs/${pageId}/editing`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
                body: JSON.stringify({ editor_id: editorIdRef.current, release }),
            }).then((r) => r.json())
                .then((d) => { if (!stop && !release) setEditedBy(d.others?.[0] ?? null); })
                .catch(() => {});
        };
        beat();
        const t = setInterval(() => beat(), 45000);
        return () => { stop = true; clearInterval(t); beat(true); };
    }, [pageId]);

    // /form substep buttons bubble up from the node views; they summon the
    // app-wide drawer with this page's context, and the result flashes back.
    useEffect(() => {
        const el = wrapRef.current;
        if (!el) return;
        const h = (e) => {
            const { mode, kind } = e.detail || {};
            if (!kind) return;
            openRecordForm({
                mode, kind, companyId,
                onSaved: (rec) => {
                    const label = rec?.asset_tag || rec?.identifier || rec?.name || '';
                    setFlash(`✓ ${kind} ${mode === 'edit' ? 'updated' : 'recorded'}${label ? `: ${label}` : ''}`);
                    setTimeout(() => setFlash(null), 4000);
                },
            });
        };
        el.addEventListener('sop-form-open', h);
        return () => el.removeEventListener('sop-form-open', h);
    }, [companyId]);

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

    const helpRow = (cmd, text) => (
        <li className="flex gap-3"><code className="shrink-0 w-40 text-blue-700 dark:text-blue-300">{cmd}</code><span className="text-gray-600 dark:text-gray-300">{text}</span></li>
    );

    return (
        <div className="relative" ref={wrapRef}>
            {editedBy && (
                <div className="mb-2 inline-flex items-center gap-2 rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-800 dark:text-amber-300">
                    <span aria-hidden>🔒</span>
                    {editedBy.you ? 'You have this doc open in another window' : `Being edited by ${editedBy.user}`} — simultaneous edits will conflict.
                </div>
            )}
            {flash && (
                <div className="fixed bottom-4 right-4 z-50 rounded-md border border-green-200 dark:border-green-900 bg-green-50 dark:bg-green-500/10 px-3 py-1.5 text-sm text-green-700 dark:text-green-400 shadow-sm">
                    {flash}
                </div>
            )}
            {helpOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" onClick={() => setHelpOpen(false)}>
                    <div onClick={(e) => e.stopPropagation()}
                        className="max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 text-sm shadow-xl">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">SOP cheatsheet</h2>
                            <button onClick={() => setHelpOpen(false)} className="px-2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">×</button>
                        </div>
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Structure</p>
                        <ul className="mb-3 space-y-1">
                            {helpRow('/sop', 'Insert or refresh the header table — adds missing rows, keeps your values. OS is a picker; Tools and Safety lines become real tasks ahead of the procedure.')}
                            {helpRow('/step', 'A new step card — one action per card, written as a command. ↑↓ move · ≡ collapse · ⊞ add a Why/How/Done-when table · ↳+ add a substep · × remove.')}
                            {helpRow('/table', 'Generic table, the SOP header, or a step-fields table.')}
                        </ul>
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Live commands (insert as substeps)</p>
                        <ul className="mb-3 space-y-1">
                            {helpRow('/install name', 'Pull an installer from the share — picker reads the catalog; "/install mac office" filters by platform.')}
                            {helpRow('/vpn profile', 'Pull and import a VPN config from the share.')}
                            {helpRow('/mdm system auto|manual', 'Enroll into Jamf, Intune, … auto = bought on the business account, in ABM, zero-touch. manual = retail-bought, profile staged and approved by hand.')}
                            {helpRow('/form new device', 'The generated task carries an add-record form (new) or a pick-and-edit form (edit) — device, person, account, location.')}
                            {helpRow('/banner, /wifi…', 'Commands from Docs > Commands — args after the name map onto their params; anything left out is asked at the bench. Add your own there.')}
                        </ul>
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Header rows</p>
                        <p className="mb-3 text-gray-600 dark:text-gray-300">Every row is optional. Why = purpose, How = approach, Scope = boundaries. The OS row decides which script the SOP generates. Owner is the one person who keeps the document true; Version bumps on every change; Status stays Draft until approved.</p>
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Placeholders (fill at run time)</p>
                        <p className="text-gray-600 dark:text-gray-300"><code>{'{first} {last} {username} {email} {start_date} {local_domain} {domain}'}</code> in people SOPs; <code>{'{ASSET_TAG} {REPO} {BASE_URL}'}</code> in scripts.</p>
                    </div>
                </div>
            )}
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
