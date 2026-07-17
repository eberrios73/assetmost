import { useEffect, useState } from 'react';
import SearchSelect from '@/Components/SearchSelect';
import { EyeIcon, EyeOffIcon } from '@/Components/Icons';

/**
 * Multi-value picker: chips for what's assigned, one SearchSelect to add more.
 * `knownLabels` seeds names for ids that came with the record (the options
 * endpoint also has them, but this avoids a flash of bare ids on open).
 */
function MultiPicker({ ids, endpoint, knownLabels, onChange, placeholder = 'Add…' }) {
    const [options, setOptions] = useState(knownLabels);

    useEffect(() => {
        fetch(endpoint, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((all) => setOptions((prev) => {
                const seen = new Set(all.map((o) => o.id));
                return [...all, ...prev.filter((o) => !seen.has(o.id))];
            }));
    }, [endpoint]);

    const label = (id) => options.find((o) => o.id === id)?.label ?? `#${id}`;

    return (
        <div className="space-y-2">
            {ids.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {ids.map((id) => (
                        <span key={id} className="inline-flex items-center gap-1 rounded-full bg-blue-50 dark:bg-blue-500/15 px-2.5 py-1 text-xs text-blue-700 dark:text-blue-300">
                            {label(id)}
                            <button type="button" onClick={() => onChange(ids.filter((x) => x !== id))}
                                className="text-blue-400 hover:text-blue-700 dark:hover:text-blue-200 leading-none">×</button>
                        </span>
                    ))}
                </div>
            )}
            <SearchSelect value={null} options={options.filter((o) => !ids.includes(o.id))}
                onChange={(id) => { if (id) onChange([...ids, id]); }} placeholder={placeholder} />
        </div>
    );
}

/**
 * Create or edit a record in a right-side sliding drawer (keeps the list visible
 * behind it). method 'POST' = create, 'PATCH' = edit. Driven by `fields`.
 */
export default function RecordModal({ title, endpoint, method = 'POST', fields, initial = {}, onClose, onSaved }) {
    const [values, setValues] = useState(() =>
        Object.fromEntries(fields.map((f) => [
            f.key,
            initial[f.key] ?? (f.type === 'multi-select-search' ? [] : ''),
        ]))
    );
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [shown, setShown] = useState(false);
    const [revealed, setRevealed] = useState({});   // { [fieldKey]: true }

    useEffect(() => { const t = requestAnimationFrame(() => setShown(true)); return () => cancelAnimationFrame(t); }, []);
    const close = () => { setShown(false); setTimeout(onClose, 200); };

    /** Password fields ship blank ("blank = keep"), so revealing has to fetch the real
     *  secret from the gated endpoint. Once loaded it's editable; saving it unchanged
     *  just rewrites the same value. */
    const toggleReveal = async (f) => {
        if (revealed[f.key]) { setRevealed((r) => ({ ...r, [f.key]: false })); return; }
        if (f.revealEndpoint && initial?.id && !values[f.key]) {
            try {
                const res = await fetch(f.revealEndpoint(initial.id), {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin',
                });
                const body = await res.json();
                setValues((v) => ({ ...v, [f.key]: body[f.revealKey || 'password'] ?? '' }));
            } catch { /* leave the field as-is */ }
        }
        setRevealed((r) => ({ ...r, [f.key]: true }));
    };

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true); setErrors({});
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const res = await fetch(endpoint, {
            method, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify(values),
        });
        setSaving(false);
        if (res.ok) { onSaved(await res.json().catch(() => ({}))); return; }
        if (res.status === 422) { const b = await res.json(); setErrors(b.errors || {}); return; }
        setErrors({ _: ['Could not save (' + res.status + ').'] });
    };

    return (
        <div className="fixed inset-0 z-50">
            <div className={`absolute inset-0 bg-black/25 transition-opacity duration-200 ${shown ? 'opacity-100' : 'opacity-0'}`} onClick={close} />
            <div className={`absolute right-0 top-0 h-full w-full max-w-md bg-white dark:bg-gray-900 shadow-2xl flex flex-col transition-transform duration-200 ease-out ${shown ? 'translate-x-0' : 'translate-x-full'}`}>
                <div className="flex items-center justify-between border-b border-gray-100 dark:border-gray-800 px-5 py-3">
                    <span className="font-semibold text-gray-800 dark:text-gray-100">{title}</span>
                    <button onClick={close} className="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 text-xl leading-none">×</button>
                </div>
                <form onSubmit={submit} className="flex-1 overflow-y-auto p-5 space-y-4">
                    {errors._ && <div className="text-sm text-red-600">{errors._[0]}</div>}
                    {fields.map((f) => (
                        <div key={f.key}>
                            <label className="block text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1">
                                {f.label}{f.required && ' *'}
                            </label>
                            {f.type === 'checkbox' ? (
                                <input type="checkbox" checked={!!values[f.key]}
                                    onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.checked }))}
                                    className="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-blue-600 focus:ring-blue-500" />
                            ) : f.type === 'textarea' ? (
                                <textarea rows={3} value={values[f.key] ?? ''}
                                    onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                    className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            ) : f.type === 'select-search' ? (
                                <SearchSelect value={values[f.key]} endpoint={f.optionsEndpoint}
                                    fallbackLabel={f.labelField ? (initial[f.labelField] ?? '') : ''}
                                    onChange={(id) => setValues((v) => ({ ...v, [f.key]: id }))} placeholder={`Search ${f.label.toLowerCase()}…`} />
                            ) : f.type === 'multi-select-search' ? (
                                <MultiPicker ids={values[f.key] || []} endpoint={f.optionsEndpoint}
                                    knownLabels={initial[f.optionsKey || 'holder_options'] || []}
                                    placeholder={f.pickPlaceholder || 'Add…'}
                                    onChange={(ids) => setValues((v) => ({ ...v, [f.key]: ids }))} />
                            ) : f.type === 'select' ? (
                                <select value={values[f.key] ?? ''}
                                    onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                    className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500">
                                    {!f.required && <option value="">—</option>}
                                    {(f.options || []).map((o) => (
                                        <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? o}</option>
                                    ))}
                                </select>
                            ) : f.type === 'password' ? (
                                <div className="relative">
                                    <input type={revealed[f.key] ? 'text' : 'password'}
                                        value={values[f.key] ?? ''}
                                        onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                        className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500 pr-9 font-mono" />
                                    <button type="button" onClick={() => toggleReveal(f)}
                                        title={revealed[f.key] ? 'Hide password' : 'Show password'}
                                        className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                                        {revealed[f.key] ? <EyeOffIcon /> : <EyeIcon />}
                                    </button>
                                </div>
                            ) : (
                                <input type={f.type || 'text'} maxLength={f.maxLength}
                                    value={values[f.key] ?? ''}
                                    onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                    className="w-full rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            )}
                            {errors[f.key] && <div className="text-xs text-red-600 mt-1">{errors[f.key][0]}</div>}
                        </div>
                    ))}
                </form>
                <div className="border-t border-gray-100 dark:border-gray-800 px-5 py-3 flex justify-end gap-2">
                    <button type="button" onClick={close} className="px-4 py-2 text-sm rounded-md border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">Cancel</button>
                    <button onClick={submit} disabled={saving} className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white disabled:opacity-50">
                        {saving ? 'Saving…' : 'Save'}
                    </button>
                </div>
            </div>
        </div>
    );
}
