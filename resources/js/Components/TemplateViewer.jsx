import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableHeader } from '@tiptap/extension-table-header';
import { TableCell } from '@tiptap/extension-table-cell';
import { SopStep, SopListItem, CmdPills, JoinSubLists } from '@/Components/DocEditor';

/**
 * A template, rendered by the SAME editor a real SOP uses — read-only. Raw
 * HTML can't fake the step cards and header table (they're node views and
 * editor CSS), so we don't fake: we mount the editor and lock it.
 */
export default function TemplateViewer({ html, editable = false, onSave = null }) {
    const saveTimer = { current: null };
    const editor = useEditor({
        editable,
        onUpdate: onSave ? ({ editor }) => {
            clearTimeout(saveTimer.current);
            saveTimer.current = setTimeout(() => onSave(editor.getHTML()), 700);
        } : undefined,
        extensions: [
            StarterKit.configure({ codeBlock: false, listItem: false }),
            SopListItem, SopStep, CmdPills, JoinSubLists,
            Table.configure({ resizable: false }), TableRow, TableHeader, TableCell,
        ],
        content: html,
        editorProps: { attributes: { class: 'prose prose-sm dark:prose-invert max-w-none focus:outline-none' + (editable ? ' min-h-[40vh]' : '') } },
    }, [html]);
    return <EditorContent editor={editor} />;
}
