import Field from '@/Components/detail/Field';

export default function LocationDetail({ l }) {
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

            <h3 className="mb-2 text-sm font-medium text-gray-700">Rooms</h3>
            {l.rooms?.length ? (
                <table className="w-full text-sm">
                    <tbody>{l.rooms.map((r) => (
                        <tr key={r.id} className="border-b border-gray-50 dark:border-gray-800">
                            <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{r.name}</td>
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{r.room_type}</td>
                        </tr>
                    ))}</tbody>
                </table>
            ) : <p className="text-sm text-gray-400">No rooms at this location.</p>}
        </div>
    );
}

function Stat({ label, value }) {
    return (
        <div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-3">
            <div className="text-xl font-semibold text-gray-800">{value ?? 0}</div>
            <div className="text-xs uppercase tracking-wide text-gray-400">{label}</div>
        </div>
    );
}
