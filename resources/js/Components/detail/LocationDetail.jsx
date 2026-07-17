import { useState } from 'react';
import Field from '@/Components/detail/Field';
import DataTable from '@/Components/ui/DataTable';
import RecordModal from '@/Components/RecordModal';

// No location picker here — the chain is the point: you're IN the location,
// so a room created here belongs to it.
const ROOM_FIELDS = [
    { key: 'name', label: 'Name', required: true },
    { key: 'room_type', label: 'Type' },
    { key: 'room_number', label: 'Number' },
    { key: 'capacity', label: 'Capacity', type: 'number' },
];

/** A place, and everything in it. Rooms live HERE — a room is meaningless
 *  without its location, so there is no separate Rooms screen. */
export default function LocationDetail({ l, onChanged }) {
    const [adding, setAdding] = useState(false);
    const [edit, setEdit] = useState(null);

    const columns = [
        { key: 'name', label: 'Room', width: '34%', className: 'text-gray-800 dark:text-gray-200' },
        { key: 'room_type', label: 'Type', width: '26%' },
        { key: 'room_number', label: 'Number', width: '20%' },
        { key: 'capacity', label: 'Capacity', width: '20%', sortValue: (r) => r.capacity ?? 0 },
    ];

    return (
        <div className="max-w-3xl">
            <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{l.name}</h2>
            <p className="text-sm text-gray-500 mb-4">{l.type}{[l.city, l.state].filter(Boolean).length ? ` · ${[l.city, l.state].filter(Boolean).join(', ')}` : ''}</p>

            <div className="grid grid-cols-2 gap-4 mb-6 max-w-md">
                <Stat label="Rooms" value={l.rooms?.length ?? 0} />
                <Stat label="Devices" value={l.devices_count} />
            </div>

            <dl className="grid grid-cols-2 gap-x-8 mb-6">
                <Field label="Type" value={l.type} />
                <Field label="Address" value={[l.address, l.city, l.state, l.zip].filter(Boolean).join(', ')} />
                <Field label="Active" value={l.active ? 'Yes' : 'No'} />
            </dl>

            <h3 className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Rooms</h3>
            <DataTable columns={columns} rows={l.rooms || []} onRowClick={setEdit}
                addLabel="Add room" onAdd={() => setAdding(true)}
                emptyText="No rooms at this location." />

            {adding && (
                <RecordModal title={`Add room — ${l.name}`} endpoint="/data/rooms" method="POST"
                    fields={ROOM_FIELDS} extra={{ location_id: l.id }}
                    onClose={() => setAdding(false)}
                    onSaved={() => { setAdding(false); onChanged?.(); }} />
            )}
            {edit && (
                <RecordModal title={edit.name} endpoint={`/data/rooms/${edit.id}`} method="PATCH"
                    fields={ROOM_FIELDS} initial={edit}
                    onClose={() => setEdit(null)}
                    onSaved={() => { setEdit(null); onChanged?.(); }} />
            )}
        </div>
    );
}

function Stat({ label, value }) {
    return (
        <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-3">
            <div className="text-xl font-semibold text-gray-800 dark:text-gray-100">{value ?? 0}</div>
            <div className="text-xs uppercase tracking-wide text-gray-400">{label}</div>
        </div>
    );
}
