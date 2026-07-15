// Doc templates — shared by the Docs "New" menu and the "Make doc" action on a task.
// Bodies are TipTap-compatible HTML.

const SOP = `
<table><tbody>
<tr><th>Owner</th><td></td><th>Version</th><td>1.0</td></tr>
<tr><th>Effective</th><td></td><th>Review by</th><td></td></tr>
<tr><th>Approver</th><td></td><th>Status</th><td>Draft</td></tr>
</tbody></table>
<h2>Purpose</h2><p></p>
<h2>Scope</h2><p></p>
<h2>Prerequisites</h2><ul><li></li></ul>
<h2>Procedure</h2>
<table><tbody>
<tr><th>#</th><th>Action</th><th>Responsible</th><th>Notes</th></tr>
<tr><td>1</td><td></td><td></td><td></td></tr>
<tr><td>2</td><td></td><td></td><td></td></tr>
<tr><td>3</td><td></td><td></td><td></td></tr>
</tbody></table>
<h2>Verification</h2><p></p>
<h2>Rollback / recovery</h2><p></p>
<h2>Revision history</h2>
<table><tbody>
<tr><th>Date</th><th>Version</th><th>Change</th><th>By</th></tr>
<tr><td></td><td>1.0</td><td>Initial version</td><td></td></tr>
</tbody></table>
`.trim();

const TROUBLESHOOTING = `
<h2>Symptom</h2><p></p>
<h2>Affected systems</h2><p></p>
<h2>Diagnosis &amp; fixes</h2>
<table><tbody>
<tr><th>Symptom</th><th>Likely cause</th><th>Check</th><th>Resolution</th></tr>
<tr><td></td><td></td><td></td><td></td></tr>
<tr><td></td><td></td><td></td><td></td></tr>
<tr><td></td><td></td><td></td><td></td></tr>
</tbody></table>
<h2>Escalation</h2><p></p>
`.trim();

const INCIDENT = `
<table><tbody>
<tr><th>Severity</th><td>Sev3</td><th>Status</th><td>Investigating</td></tr>
<tr><th>Detected</th><td></td><th>Resolved</th><td></td></tr>
<tr><th>Owner</th><td></td><th>Reported by</th><td></td></tr>
</tbody></table>
<h2>Summary</h2><p></p>
<h2>Impact</h2><p></p>
<h2>Affected systems / users</h2><ul><li></li></ul>
<h2>Timeline</h2>
<table><tbody>
<tr><th>Time</th><th>Event / action</th><th>By</th></tr>
<tr><td></td><td></td><td></td></tr>
<tr><td></td><td></td><td></td></tr>
</tbody></table>
<h2>Root cause</h2><p></p>
<h2>Resolution</h2><p></p>
<h2>Follow-up actions</h2>
<table><tbody>
<tr><th>Action</th><th>Owner</th><th>Due</th><th>Status</th></tr>
<tr><td></td><td></td><td></td><td></td></tr>
</tbody></table>
`.trim();

export const DOC_TEMPLATES = [
    { key: 'sop', label: 'SOP', hint: 'Standard operating procedure', icon: '📋', body: SOP },
    { key: 'troubleshooting', label: 'Troubleshooting guide', hint: 'Symptom → diagnosis → fix', icon: '🔧', body: TROUBLESHOOTING },
    { key: 'incident', label: 'Incident report', hint: 'What happened, impact, root cause, follow-up', icon: '🚨', body: INCIDENT },
    { key: 'freeform', label: 'Free form', hint: 'Blank page', icon: '📄', body: '' },
];

const esc = (s) => String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
const toParas = (text) => esc(text).split(/\n{2,}/).map((p) => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');

const FREEFORM = DOC_TEMPLATES.find((t) => t.key === 'freeform');

/** Build a doc body from a template, optionally seeding a Background section from task notes. */
export function buildDocBody(templateKey, background = '') {
    const tpl = DOC_TEMPLATES.find((t) => t.key === templateKey) || FREEFORM;
    const bg = background && background.trim() ? `<h2>Background</h2>${toParas(background.trim())}` : '';
    return (bg + tpl.body) || '<p></p>';
}

export function templateIcon(templateKey) {
    return (DOC_TEMPLATES.find((t) => t.key === templateKey) || FREEFORM).icon;
}
