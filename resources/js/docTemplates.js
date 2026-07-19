// Doc templates — shared by the Docs "New" menu and the "Make doc" action on a task.
// Bodies are TipTap-compatible HTML.

// The header rows and the step cards are ENGINE-NATIVE: header rows compile to the
// workflow's meta (Tools/Safety become real tasks ahead of the procedure), step cards
// compile to chained checklist tasks. Guidance paragraphs are deliberately over 20
// words — the parser treats long prose as context, never as a step.
const SOP = `
<table><tbody>
<tr><td><p><strong>Why:</strong></p></td><td colspan="7"><p></p></td></tr>
<tr><td><p><strong>How:</strong></p></td><td colspan="7"><p></p></td></tr>
<tr><td><p><strong>Scope:</strong></p></td><td colspan="7"><p></p></td></tr>
<tr><td><p><strong>Tools and Materials:</strong></p></td><td colspan="7"><p></p></td></tr>
<tr><td><p><strong>Safety Precautions:</strong></p></td><td colspan="7"><p></p></td></tr>
<tr><td><p><strong>OS:</strong></p></td><td><p></p></td><td><p><strong>Owner:</strong></p></td><td><p>__OWNER__</p></td><td><p><strong>Version:</strong></p></td><td><p>1.0</p></td><td><p><strong>Status:</strong></p></td><td><p>Draft</p></td></tr>
</tbody></table>
<p><em>Every header row is optional — delete the ones this procedure doesn't need. Why is the purpose; How is the approach in one line; Tools and Safety are one item per line and become REAL tasks ahead of the procedure when this SOP runs. Owner is the ONE person who keeps this document true; Version bumps on every change and gets a line in Revision history.</em></p>
<h2>Procedure</h2>
<p><em>One action per step card, in order, written as a command ("Lock out the power source"). Type /step for a new card, use the card's ↳+ for substeps and ⊞ for a Why/How/Done-when table on a step that earns one; /install, /vpn, /mdm and /form drop live commands into the procedure.</em></p>
<section data-sop-step><p><strong>New step</strong></p><p></p></section>
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

// Same 2-column label/value ease as the SOP: fill the cells, add or remove rows.
const INCIDENT = `
<h2>Incident summary</h2>
<table><tbody>
<tr><td><p><strong>Severity:</strong></p></td><td><p>Sev3</p></td></tr>
<tr><td><p><strong>Status:</strong></p></td><td><p>Investigating</p></td></tr>
<tr><td><p><strong>Incident start date:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Incident end date:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Executive summary / Description:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Symptoms, if any:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Source of detection:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Owner:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Reported by:</strong></p></td><td><p></p></td></tr>
</tbody></table>
<h2>Impact assessment</h2>
<table><tbody>
<tr><td><p><strong>Affected users:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Affected services:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Affected devices / assets:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Other impact details:</strong></p></td><td><p></p></td></tr>
</tbody></table>
<h2>Timeline of events</h2>
<table><tbody>
<tr><th>Date</th><th>Event</th><th>By</th></tr>
<tr><td></td><td></td><td></td></tr>
<tr><td></td><td></td><td></td></tr>
</tbody></table>
<h2>Analysis</h2>
<table><tbody>
<tr><td><p><strong>Root cause of the incident:</strong></p></td><td><p></p></td></tr>
<tr><td><p><strong>Similar incidents:</strong></p></td><td><p></p></td></tr>
</tbody></table>
<h2>Remediation actions</h2>
<ol><li><p></p></li></ol>
<h2>Follow-up actions</h2>
<ol><li><p></p></li></ol>
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
export function buildDocBody(templateKey, background = '', vars = {}) {
    const tpl = DOC_TEMPLATES.find((t) => t.key === templateKey) || FREEFORM;
    const bg = background && background.trim() ? `<h2>Background</h2>${toParas(background.trim())}` : '';
    const body = (bg + tpl.body).replaceAll('__OWNER__', esc(vars.owner || ''));
    return body || '<p></p>';
}

export function templateCategory(templateKey) {
    return (DOC_TEMPLATES.find((t) => t.key === templateKey) || {}).category || '';
}
