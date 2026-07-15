import { useEffect, useState } from 'react';

const STEPS = ['Identity', 'Placement', 'Review'];
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');

/** Self-contained wizard to onboard a new device into inventory. */
export default function AssetOnboard({ onDone }) {
    const [step, setStep] = useState(0);
    const [form, setForm] = useState({ asset_tag: '', type: '', brand: '', model: '', serial_num: '', location_id: '', room_id: '' });
    const [types, setTypes] = useState([]);
    const [locations, setLocations] = useState([]);
    const [rooms, setRooms] = useState([]);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
    const get = (u) => fetch(u, { headers: { Accept: 'application/json' } }).then((r) => r.json());

    useEffect(() => { get('/data/device-types').then(setTypes); get('/data/locations?active_only=0&offset=0').then((d) => setLocations(d.items || [])); }, []);
    useEffect(() => {
        if (!form.location_id) { setRooms([]); return; }
        get(`/data/rooms?active_only=0&offset=0`).then((d) => setRooms((d.items || [])));
    }, [form.location_id]);

    const submit = async () => {
        setSaving(true); setErrors({});
        const res = await fetch('/data/devices', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ ...form, location_id: form.location_id || null, room_id: form.room_id || null }),
        });
        setSaving(false);
        if (res.status === 201) { onDone?.(await res.json()); return; }
        if (res.status === 422) setErrors((await res.json()).errors || {});
    };

    return (
        <div className="max-w-2xl">
            {/* progress */}
            <div className="flex items-center gap-2 mb-6">
                {STEPS.map((s, i) => (
                    <div key={s} className="flex items-center gap-2">
                        <span className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-medium ${i <= step ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500'}`}>{i + 1}</span>
                        <span className={`text-sm ${i === step ? 'text-gray-900 font-medium' : 'text-gray-400'}`}>{s}</span>
                        {i < STEPS.length - 1 && <span className="w-8 h-px bg-gray-200 mx-1" />}
                    </div>
                ))}
            </div>

            {step === 0 && (
                <Grid>
                    <Field label="Asset tag"><input className={inp} value={form.asset_tag} onChange={(e) => set('asset_tag', e.target.value)} /></Field>
                    <Field label="Type">
                        <select className={inp} value={form.type} onChange={(e) => set('type', e.target.value)}>
                            <option value="">—</option>{types.map((t) => <option key={t} value={t}>{t}</option>)}
                        </select>
                    </Field>
                    <Field label="Brand"><input className={inp} value={form.brand} onChange={(e) => set('brand', e.target.value)} /></Field>
                    <Field label="Model"><input className={inp} value={form.model} onChange={(e) => set('model', e.target.value)} /></Field>
                    <Field label="Serial"><input className={inp} value={form.serial_num} onChange={(e) => set('serial_num', e.target.value)} /></Field>
                </Grid>
            )}
            {step === 1 && (
                <Grid>
                    <Field label="Location">
                        <select className={inp} value={form.location_id} onChange={(e) => { set('location_id', e.target.value); set('room_id', ''); }}>
                            <option value="">—</option>{locations.map((l) => <option key={l.id} value={l.id}>{l.primary}</option>)}
                        </select>
                    </Field>
                    <Field label="Room">
                        <select className={inp} value={form.room_id} onChange={(e) => set('room_id', e.target.value)} disabled={!rooms.length}>
                            <option value="">—</option>{rooms.map((r) => <option key={r.id} value={r.id}>{r.primary}</option>)}
                        </select>
                    </Field>
                </Grid>
            )}
            {step === 2 && (
                <div className="rounded-lg border border-gray-200 p-4 text-sm">
                    <Row k="Asset tag" v={form.asset_tag} />
                    <Row k="Type" v={form.type} />
                    <Row k="Brand / Model" v={[form.brand, form.model].filter(Boolean).join(' ')} />
                    <Row k="Serial" v={form.serial_num} />
                    <Row k="Location" v={locations.find((l) => String(l.id) === String(form.location_id))?.primary} />
                    <Row k="Room" v={rooms.find((r) => String(r.id) === String(form.room_id))?.primary} />
                    {errors._ && <p className="text-red-600 mt-2">Could not save.</p>}
                </div>
            )}

            <div className="mt-6 flex justify-between">
                <button disabled={step === 0} onClick={() => setStep((s) => s - 1)} className="px-4 py-2 text-sm rounded-md border border-gray-200 text-gray-600 disabled:opacity-40">Back</button>
                {step < STEPS.length - 1
                    ? <button onClick={() => setStep((s) => s + 1)} className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white">Next</button>
                    : <button onClick={submit} disabled={saving} className="px-4 py-2 text-sm rounded-md bg-blue-600 text-white disabled:opacity-50">{saving ? 'Adding…' : 'Add asset'}</button>}
            </div>
        </div>
    );
}

const inp = 'w-full rounded-md border-gray-200 text-sm focus:border-blue-500 focus:ring-blue-500';
function Grid({ children }) { return <dl className="grid grid-cols-2 gap-x-8 gap-y-3 max-w-xl">{children}</dl>; }
function Field({ label, children }) { return <div><label className="block text-xs uppercase tracking-wide text-gray-400 mb-1">{label}</label>{children}</div>; }
function Row({ k, v }) { return <div className="flex justify-between border-b border-gray-50 dark:border-gray-800 py-1.5"><span className="text-gray-400">{k}</span><span className="text-gray-800">{v || '—'}</span></div>; }
