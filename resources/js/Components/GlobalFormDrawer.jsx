import { useEffect, useState } from 'react';
import RecordModal from '@/Components/RecordModal';
import SearchSelect from '@/Components/SearchSelect';
import { ENTITIES } from '@/entities';
import { onRecordForm } from '@/lib/formBus';

const FORM_ENTITY = { device: 'devices', person: 'people', account: 'accounts', location: 'locations' };

/**
 * The one record drawer, mounted once in AppShell and summoned from anywhere
 * via openRecordForm() — SOP /form buttons, task checklists, the palette later.
 * 'new' opens the add drawer scoped to the caller's company; 'edit' asks which
 * record first (company-scoped options), then opens the same drawer in edit
 * mode. The saved record flows back to the caller through onSaved.
 */
export default function GlobalFormDrawer() {
    const [spec, setSpec] = useState(null);      // the open request
    const [editRec, setEditRec] = useState(null);

    useEffect(() => onRecordForm((s) => {
        if (!s || !FORM_ENTITY[s.kind]) return;
        setEditRec(null);
        setSpec({ mode: s.mode === 'edit' ? 'edit' : 'new', ...s });
    }), []);

    if (!spec) return null;
    const entity = ENTITIES[FORM_ENTITY[spec.kind]];
    if (!entity) return null;
    const close = () => { setSpec(null); setEditRec(null); };

    if (spec.mode === 'edit' && !editRec) {
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4" onClick={close}>
                <div onClick={(e) => e.stopPropagation()}
                    className="w-full max-w-md rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 shadow-xl">
                    <p className="mb-2 text-sm font-medium text-gray-800 dark:text-gray-100">Pick the {spec.kind} to update</p>
                    <SearchSelect value={null} portal
                        endpoint={`/data/form-options?kind=${spec.kind}${spec.companyId ? `&company=${spec.companyId}` : ''}`}
                        placeholder={`Search ${spec.kind}s…`}
                        onChange={(id) => {
                            if (!id) return;
                            fetch(entity.detailEndpoint(id), { headers: { Accept: 'application/json' } })
                                .then((r) => (r.ok ? r.json() : null))
                                .then((rec) => { if (rec) setEditRec(rec); });
                        }} />
                </div>
            </div>
        );
    }

    return (
        <RecordModal
            title={spec.mode === 'edit' ? `Edit ${spec.kind}` : `Add ${spec.kind}`}
            endpoint={spec.mode === 'edit' ? entity.detailEndpoint(editRec.id) : entity.add.endpoint}
            method={spec.mode === 'edit' ? 'PATCH' : 'POST'}
            fields={spec.mode === 'edit' ? entity.edit.fields : entity.add.fields}
            initial={spec.mode === 'edit' ? editRec
                // Prefill the caller's company so it SHOWS in the form (an empty
                // company field in `values` would override `extra` on submit).
                : { ...(spec.companyId ? { company_id: spec.companyId } : {}), ...(spec.initial || {}) }}
            extra={spec.mode === 'new' && spec.companyId ? { company_id: spec.companyId } : {}}
            onClose={close}
            onSaved={(rec) => { close(); spec.onSaved?.(rec); }} />
    );
}
