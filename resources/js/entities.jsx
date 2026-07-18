import { DeviceIcon, PersonIcon, VendorIcon, RoomIcon, ClientIcon, KeyIcon } from '@/Components/Icons';
import DeviceDetail from '@/Components/detail/DeviceDetail';
import PersonDetail from '@/Components/detail/PersonDetail';
import VendorDetail from '@/Components/detail/VendorDetail';
import RoomDetail from '@/Components/detail/RoomDetail';
import LocationDetail from '@/Components/detail/LocationDetail';
import CompanyDetail from '@/Components/detail/CompanyDetail';
import AccountDetail from '@/Components/detail/AccountDetail';

// A floating account is the credential IDENTITY only — the passwords live on
// the service logins that use it. Floating means no 'personal' option here.
const ACCOUNT_FIELDS = [
    { key: 'identifier', label: 'Email / username', required: true },
    { key: 'sharing', label: 'Sharing', type: 'select', required: true, options: [
        { value: 'pooled', label: 'Pooled — one at a time' },
        { value: 'shared', label: 'Shared — many at once' },
        { value: 'service', label: 'Service — runs the system, no human holder' },
        { value: 'breakglass', label: 'Break glass — sealed emergency access' },
    ] },
    { key: 'holder_ids', label: 'Assigned to', type: 'multi-select-search',
      optionsEndpoint: '/data/person-options', pickPlaceholder: 'Search people to add…' },
    { key: 'notes', label: 'Notes', type: 'textarea' },
    { key: 'is_active', label: 'Active', type: 'checkbox' },
];

/** One config per entity; screens/groups compose these. */
export const ENTITIES = {
    people: {
        noun: 'a staff member',
        listEndpoint: '/data/people', detailEndpoint: (id) => `/data/people/${id}`,
        icon: <PersonIcon />, idLabel: 'Person ID',
        filter: { key: 'department', label: 'departments', optionsEndpoint: '/data/departments' },
        sort: [{ key: 'name', label: 'First name' }, { key: 'last', label: 'Last name' }, { key: 'department', label: 'Department' }, { key: 'ext', label: 'Ext' }],
        render: (u) => <PersonDetail u={u} />,
        add: {
            endpoint: '/data/people', title: 'Add Staff',
            fields: [
                { key: 'name', label: 'First name', required: true },
                { key: 'last', label: 'Last name' },
                { key: 'email', label: 'Email', type: 'email' },
                { key: 'title', label: 'Title' },
                { key: 'department', label: 'Department' },
                { key: 'cell', label: 'Cell' },
                { key: 'ext', label: 'Ext' },
                // Defaults to the company in the switcher; pick one to file them elsewhere.
                { key: 'company_id', label: 'Company', type: 'select-search', optionsEndpoint: '/data/company-options' },
                // Most staff are a directory record and never sign in. Access is stated
                // here, not inferred from whether a password happens to exist.
                { key: 'role', label: 'Role', type: 'select', options: [
                    { value: 'User', label: 'User — directory record, no app access' },
                    { value: 'Operations', label: 'Operations — everything except passwords' },
                    { value: 'IT Admin', label: 'IT Admin — including passwords' },
                    { value: 'SuperAdmin', label: 'SuperAdmin — full access' },
                ] },
                { key: 'can_login', label: 'Can sign in to AssetMost', type: 'checkbox' },
                { key: 'password', label: 'Password (only if they can sign in)', type: 'password' },
            ],
        },
        edit: { fields: [
            { key: 'name', label: 'First name', required: true }, { key: 'last', label: 'Last name' },
            { key: 'email', label: 'Email', type: 'email' }, { key: 'title', label: 'Title' },
            { key: 'department', label: 'Department' }, { key: 'cell', label: 'Cell' },
            { key: 'ext', label: 'Ext' }, { key: 'active', label: 'Active', type: 'checkbox' },
        ] },
    },
    accounts: {
        noun: 'an account',
        listEndpoint: '/data/accounts', detailEndpoint: (id) => `/data/accounts/${id}`,
        icon: <KeyIcon />, idLabel: 'Account ID',
        // A map of the realm's admin credentials — the list itself is the sensitive
        // thing. Re-enter your password on every visit; the server enforces its own
        // window on top (423 without a fresh unlock).
        guard: { reason: 'The account registry maps privileged credentials across your realm. Re-enter your password to open it.' },
        filter: { key: 'sharing', label: 'sharing', optionsEndpoint: '/data/sharing-options' },
        sort: [{ key: 'identifier', label: 'Email / username' }, { key: 'sharing', label: 'Sharing' }],
        render: (a) => <AccountDetail a={a} />,
        add: { endpoint: '/data/accounts', title: 'Add Account', fields: ACCOUNT_FIELDS },
        edit: { fields: ACCOUNT_FIELDS },
    },
    vendors: {
        noun: 'a vendor',
        listEndpoint: '/data/vendors', detailEndpoint: (id) => `/data/vendors/${id}`,
        icon: <VendorIcon />, idLabel: 'Vendor ID',
        sort: [{ key: 'name', label: 'Name' }, { key: 'contact_name', label: 'Contact' }],
        render: (v) => <VendorDetail v={v} />,
        add: {
            endpoint: '/data/vendors', title: 'Add Vendor',
            fields: [
                { key: 'name', label: 'Name', required: true },
                { key: 'contact_name', label: 'Contact' },
                { key: 'phone', label: 'Phone' },
                { key: 'email', label: 'Email', type: 'email' },
                { key: 'website', label: 'Website' },
            ],
        },
        edit: { fields: [
            { key: 'name', label: 'Name', required: true }, { key: 'contact_name', label: 'Contact' },
            { key: 'phone', label: 'Phone' }, { key: 'email', label: 'Email', type: 'email' },
            { key: 'website', label: 'Website' }, { key: 'active', label: 'Active', type: 'checkbox' },
        ] },
    },
    devices: {
        noun: 'a device',
        listEndpoint: '/data/devices', detailEndpoint: (id) => `/data/devices/${id}`,
        icon: <DeviceIcon />, idLabel: 'Device ID',
        filter: { key: 'type', label: 'types', optionsEndpoint: '/data/device-types' },
        sort: [{ key: 'asset_tag', label: 'Asset tag' }, { key: 'computer_name', label: 'Computer' }, { key: 'type', label: 'Type' }],
        render: (d) => <DeviceDetail d={d} />,
        edit: { fields: [
            { key: 'asset_tag', label: 'Asset tag' }, { key: 'computer_name', label: 'Computer name' },
            { key: 'type', label: 'Type' }, { key: 'brand', label: 'Brand' }, { key: 'model', label: 'Model' },
            { key: 'serial_num', label: 'Serial' }, { key: 'active', label: 'Active', type: 'checkbox' },
        ] },
    },
    locations: {
        noun: 'a location',
        listEndpoint: '/data/locations', detailEndpoint: (id) => `/data/locations/${id}`,
        icon: <RoomIcon />, idLabel: 'Location ID',
        sort: [{ key: 'name', label: 'Name' }, { key: 'city', label: 'City' }, { key: 'type', label: 'Type' }],
        render: (l, refetch) => <LocationDetail l={l} onChanged={refetch} />,
        add: {
            endpoint: '/data/locations', title: 'Add Location',
            fields: [
                { key: 'name', label: 'Name', required: true },
                { key: 'type', label: 'Type' },
                { key: 'address', label: 'Address' },
                { key: 'city', label: 'City' },
                { key: 'state', label: 'State', maxLength: 2 },
                { key: 'zip', label: 'Zip' },
            ],
        },
        edit: { fields: [
            { key: 'name', label: 'Name', required: true }, { key: 'type', label: 'Type' },
            { key: 'address', label: 'Address' }, { key: 'city', label: 'City' },
            { key: 'state', label: 'State', maxLength: 2 }, { key: 'zip', label: 'Zip' },
        ] },
    },
    rooms: {
        noun: 'a room',
        listEndpoint: '/data/rooms', detailEndpoint: (id) => `/data/rooms/${id}`,
        icon: <RoomIcon />, idLabel: 'Room ID',
        sort: [{ key: 'name', label: 'Name' }, { key: 'room_type', label: 'Type' }, { key: 'room_number', label: 'Number' }],
        render: (r) => <RoomDetail r={r} />,
        add: {
            endpoint: '/data/rooms', title: 'Add Room',
            fields: [
                { key: 'name', label: 'Name', required: true },
                // A room without a location is unplaceable, so this is required — it's the
                // whole point of rooms (walking in and knowing what should be there).
                { key: 'location_id', label: 'Location', type: 'select-search', optionsEndpoint: '/data/location-options', required: true },
                { key: 'room_type', label: 'Type' },
                { key: 'room_number', label: 'Number' },
                { key: 'capacity', label: 'Capacity' },
            ],
        },
        edit: { fields: [
            { key: 'name', label: 'Name', required: true }, { key: 'room_type', label: 'Type' },
            { key: 'room_number', label: 'Number' }, { key: 'capacity', label: 'Capacity' },
        ] },
    },
    companies: {
        noun: 'a company',
        listEndpoint: '/data/companies', detailEndpoint: (id) => `/data/companies/${id}`,
        icon: <ClientIcon />, idLabel: 'Company ID',
        sort: [{ key: 'name', label: 'Name' }, { key: 'city', label: 'City' }],
        render: (c) => <CompanyDetail c={c} />,
        add: {
            endpoint: '/data/companies', title: 'Add Company',
            fields: [
                { key: 'name', label: 'Name', required: true },
                { key: 'tag_prefix', label: 'Tag prefix (e.g. PG)', required: true, maxLength: 4 },
                { key: 'domain', label: 'Email domain' },
                { key: 'local_domain', label: 'Local domain (AD/LAN, e.g. acme.local)' },
                { key: 'installers_url', label: 'Installers URL (e.g. http://files.example.com:8080)' },
                { key: 'email', label: 'Email', type: 'email' },
                { key: 'city', label: 'City' },
                { key: 'state', label: 'State', maxLength: 2 },
            ],
        },
        edit: { fields: [
            { key: 'name', label: 'Name', required: true }, { key: 'domain', label: 'Email domain' },
            { key: 'local_domain', label: 'Local domain (AD/LAN)' },
            { key: 'installers_url', label: 'Installers URL' },
            { key: 'contact_name', label: 'Contact' }, { key: 'email', label: 'Email', type: 'email' },
            { key: 'phone', label: 'Phone' }, { key: 'address', label: 'Address' },
            { key: 'city', label: 'City' }, { key: 'state', label: 'State', maxLength: 2 },
            { key: 'zip', label: 'Zip' }, { key: 'active', label: 'Active', type: 'checkbox' },
        ] },
    },
};

// The onboarding "kinds" (StarterTemplates::KINDS server-side). People vs. asset
// procedures live under their own group's Onboarding tab — see `kinds` below.
export const ONBOARD_KINDS = {
    onboarding: 'Employee onboarding',
    freelancer: 'Freelancer onboarding',
    offboarding: 'Employee offboarding',
    imaging: 'Workstation setup',
    eprotection: 'Endpoint protection',
};

/** Top-level groups and their sub-tabs. */
export const GROUPS = {
    people: { title: 'People', tabs: [
        { key: 'staff', label: 'Staff', entity: 'people' },
        // Accounts = credentials that aren't someone's directory email: service accounts,
        // pooled seats, shared mailboxes — plus who holds each.
        { key: 'accounts', label: 'Accounts', entity: 'accounts' },
        { key: 'vendors', label: 'Vendors', entity: 'vendors' },
        { key: 'onboarding', label: 'Onboarding', view: 'onboarding', kinds: ['onboarding', 'freelancer', 'offboarding'] },
    ] },
    assets: { title: 'Assets', tabs: [
        { key: 'devices', label: 'Devices', entity: 'devices' },
        // Rooms have no tab: a room only means something inside its location, so they're
        // managed on the location's screen. (A Vehicles tab may take this slot later.)
        { key: 'locations', label: 'Locations', entity: 'locations' },
        { key: 'onboard', label: 'Onboard', view: 'asset-onboard' },
        // Machine procedures (imaging, endpoint protection) live here, not under People.
        { key: 'onboarding', label: 'Onboarding', view: 'onboarding', kinds: ['imaging', 'eprotection'] },
    ] },
    tasks: { title: 'Tasks', tabs: [{ key: 'tasks', label: 'Tasks', view: 'tasks' }] },
    docs: { title: 'Docs', tabs: [{ key: 'docs', label: 'Docs', view: 'docs' }] },
};
