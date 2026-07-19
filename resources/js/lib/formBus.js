// The record-form drawer as an app-wide service: any place in the app can open
// the ONE existing drawer (RecordModal) for any kind, pass context in
// (company, mode, prefills) and get the saved record back via onSaved —
// the SOP's /form buttons, task checklists, and later the command palette
// all call this instead of hosting their own forms.

export function openRecordForm(spec) {
    // spec: { mode: 'new'|'edit', kind: 'device'|'person'|'account'|'location',
    //         companyId?, initial?, onSaved?(record) }
    window.dispatchEvent(new CustomEvent('assetmost:record-form', { detail: spec }));
}

export function onRecordForm(handler) {
    const h = (e) => handler(e.detail);
    window.addEventListener('assetmost:record-form', h);
    return () => window.removeEventListener('assetmost:record-form', h);
}
