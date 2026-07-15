// Doc templates — shared by the Docs "New" menu and the "Make doc" action on a task.
// Bodies are TipTap-compatible HTML.

const SOP = `
<h2>Purpose</h2><p></p>
<h2>Scope</h2><p></p>
<h2>Prerequisites</h2><ul><li></li></ul>
<h2>Procedure</h2><ol><li></li><li></li></ol>
<h2>Verification</h2><p></p>
<h2>Rollback / recovery</h2><p></p>
<h2>Owner &amp; review</h2><p>Owner: <br>Last reviewed: </p>
`.trim();

const TROUBLESHOOTING = `
<h2>Symptom</h2><p></p>
<h2>Affected systems</h2><p></p>
<h2>Diagnosis</h2><ol><li></li></ol>
<h2>Common causes</h2><ul><li></li></ul>
<h2>Resolution</h2><ol><li></li></ol>
<h2>Escalation</h2><p></p>
`.trim();

export const DOC_TEMPLATES = [
    { key: 'sop', label: 'SOP', hint: 'Standard operating procedure', icon: '📋', body: SOP },
    { key: 'troubleshooting', label: 'Troubleshooting guide', hint: 'Symptom → diagnosis → fix', icon: '🔧', body: TROUBLESHOOTING },
    { key: 'freeform', label: 'Free form', hint: 'Blank page', icon: '📄', body: '' },
];

const esc = (s) => String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
const toParas = (text) => esc(text).split(/\n{2,}/).map((p) => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');

/** Build a doc body from a template, optionally seeding a Background section from task notes. */
export function buildDocBody(templateKey, background = '') {
    const tpl = DOC_TEMPLATES.find((t) => t.key === templateKey) || DOC_TEMPLATES[2];
    const bg = background && background.trim() ? `<h2>Background</h2>${toParas(background.trim())}` : '';
    return (bg + tpl.body) || '<p></p>';
}

export function templateIcon(templateKey) {
    return (DOC_TEMPLATES.find((t) => t.key === templateKey) || DOC_TEMPLATES[2]).icon;
}
