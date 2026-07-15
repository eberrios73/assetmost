import Field from '@/Components/detail/Field';

export default function RoomDetail({ r }) {
    return (
        <div className="max-w-3xl">
            <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{r.name}</h2>
            <p className="text-sm text-gray-500 mb-4">{r.room_type}{r.location?.name ? ` · ${r.location.name}` : ''}</p>
            <dl className="grid grid-cols-2 gap-x-8">
                <Field label="Location" value={r.location?.name} />
                <Field label="Room number" value={r.room_number} />
                <Field label="Capacity" value={r.capacity} />
                <Field label="Active" value={r.active ? 'Yes' : 'No'} />
            </dl>
            <h3 className="mt-6 mb-2 text-sm font-medium text-gray-700">Devices in this room ({r.devices?.length || 0})</h3>
            {r.devices?.length ? (
                <table className="w-full text-sm">
                    <tbody>{r.devices.map((d) => (
                        <tr key={d.id} className="border-b border-gray-50 dark:border-gray-800">
                            <td className="py-2 pr-4 text-gray-800 dark:text-gray-200">{d.asset_tag || d.computer_name}</td>
                            <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{d.type}</td>
                        </tr>
                    ))}</tbody>
                </table>
            ) : <p className="text-sm text-gray-400">No devices placed here.</p>}
        </div>
    );
}
