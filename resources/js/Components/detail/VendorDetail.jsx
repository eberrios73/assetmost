import Tabs from '@/Components/Tabs';
import Field from '@/Components/detail/Field';
import LoginsTable from '@/Components/detail/LoginsTable';
import LicensesTable from '@/Components/detail/LicensesTable';

export default function VendorDetail({ v }) {
    return (
        <div className="h-full flex flex-col min-h-0">
            <div className="mb-4">
                <h2 className="text-lg font-medium text-gray-800 dark:text-gray-100">{v.name}</h2>
                <p className="text-sm text-gray-500">{v.contact_name}</p>
            </div>
            <div className="flex-1 min-h-0">
                <Tabs tabs={[
                    { key: 'overview', label: 'Overview', render: () => (
                        <dl className="grid grid-cols-2 gap-x-8 max-w-2xl">
                            <Field label="Email" value={v.email} />
                            <Field label="Phone" value={v.phone} />
                            <Field label="Website" value={v.website} />
                            <Field label="Companies" value={v.companies?.map((c) => c.name).join(', ')} />
                            <Field label="Active" value={v.active ? 'Yes' : 'No'} />
                        </dl>
                    ) },
                    { key: 'logins', label: 'Logins', count: v.logins_count, render: () => <LoginsTable endpoint={`/data/vendors/${v.id}/logins`} showUser /> },
                    { key: 'licenses', label: 'Licenses', count: v.licenses_count, render: () => <LicensesTable endpoint={`/data/vendors/${v.id}/licenses`} showHolders defaults={{ vendor_id: v.id }} /> },
                ]} />
            </div>
        </div>
    );
}
