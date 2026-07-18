// Doc templates — shared by the Docs "New" menu and the "Make doc" action on a task.
// Bodies are TipTap-compatible HTML.

const SOP = `
<p>An SOP is a recipe: anyone competent should get the same result by following it. Fill in the header, list the steps in order, and say how you prove it worked. Delete the grey guidance as you go.</p>
<table><tbody>
<tr><th>Owner</th><td></td><th>Version</th><td>1.0</td></tr>
<tr><th>Effective</th><td></td><th>Review by</th><td></td></tr>
<tr><th>Approver</th><td></td><th>Status</th><td>Draft</td></tr>
</tbody></table>
<p><em>Owner — the ONE person who keeps this document true; when a step is wrong, they're who you tell. Version — bump it on every change and log it in Revision history. Effective — when this takes force. Review by — the date someone must re-read it (stale SOPs are worse than none). Approver — who signed off. Status stays Draft until they do.</em></p>
<h2>Purpose</h2><p><em>One or two sentences: what this procedure achieves, and when to use it.</em></p>
<h2>Scope</h2><p><em>What it covers — and what it deliberately does not.</em></p>
<h2>Prerequisites</h2><ul><li><em>Access, credentials, tools or parts needed BEFORE starting.</em></li></ul>
<h2>Procedure</h2>
<p><em>One action per row, in order. Notes carry the exact commands, paths and settings. These rows compile into checklist tasks.</em></p>
<table><tbody>
<tr><th>#</th><th>Action</th><th>Responsible</th><th>Notes</th></tr>
<tr><td>1</td><td></td><td></td><td></td></tr>
<tr><td>2</td><td></td><td></td><td></td></tr>
<tr><td>3</td><td></td><td></td><td></td></tr>
</tbody></table>
<h2>Verification</h2><p><em>How you PROVE it worked — something observable, not "I did the steps." (BitLocker isn't done when the bar fills; it's done when the key is visible in AD.)</em></p>
<h2>Rollback / recovery</h2><p><em>If it goes wrong: how to get back to a known-good state.</em></p>
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
    { key: 'sop', label: 'SOP', hint: 'Standard operating procedure', iconKey: 'clipboard', category: 'SOP', body: SOP },
    { key: 'troubleshooting', label: 'Troubleshooting guide', hint: 'Symptom → diagnosis → fix', iconKey: 'wrench', category: 'Troubleshooting', body: TROUBLESHOOTING },
    { key: 'incident', label: 'Incident report', hint: 'What happened, impact, root cause, follow-up', iconKey: 'alert', category: 'Incident', body: INCIDENT },
    { key: 'freeform', label: 'Free form', hint: 'Blank page', iconKey: 'doc', category: '', body: '' },
];

// Selectable doc categories (badge + filter). Templates set one automatically.
export const DOC_CATEGORIES = ['Incident', 'SOP', 'Troubleshooting', 'Runbook', 'Policy', 'Reference'];

export const CATEGORY_STYLE = {
    Incident: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
    SOP: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    Troubleshooting: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    Runbook: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
    Policy: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
    Reference: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
};

const esc = (s) => String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
const toParas = (text) => esc(text).split(/\n{2,}/).map((p) => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');

const FREEFORM = DOC_TEMPLATES.find((t) => t.key === 'freeform');

/** Build a doc body from a template, optionally seeding a Background section from task notes. */
export function buildDocBody(templateKey, background = '') {
    const tpl = DOC_TEMPLATES.find((t) => t.key === templateKey) || FREEFORM;
    const bg = background && background.trim() ? `<h2>Background</h2>${toParas(background.trim())}` : '';
    return (bg + tpl.body) || '<p></p>';
}

export function templateCategory(templateKey) {
    return (DOC_TEMPLATES.find((t) => t.key === templateKey) || {}).category || '';
}
