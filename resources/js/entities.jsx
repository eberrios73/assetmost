import { DeviceIcon, PersonIcon, VendorIcon, RoomIcon, ClientIcon } from '@/Components/Icons';
import DeviceDetail from '@/Components/detail/DeviceDetail';
import PersonDetail from '@/Components/detail/PersonDetail';
import VendorDetail from '@/Components/detail/VendorDetail';
import RoomDetail from '@/Components/detail/RoomDetail';
import LocationDetail from '@/Components/detail/LocationDetail';
import CompanyDetail from '@/Components/detail/CompanyDetail';

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
            ],
        },
        edit: { fields: [
            { key: 'name', label: 'First name', required: true }, { key: 'last', label: 'Last name' },
            { key: 'email', label: 'Email', type: 'email' }, { key: 'title', label: 'Title' },
            { key: 'department', label: 'Department' }, { key: 'cell', label: 'Cell' },
            { key: 'ext', label: 'Ext' }, { key: 'active', label: 'Active', type: 'checkbox' },
        ] },
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
        render: (l) => <LocationDetail l={l} />,
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
                { key: 'domain', label: 'Domain' },
                { key: 'email', label: 'Email', type: 'email' },
                { key: 'city', label: 'City' },
                { key: 'state', label: 'State', maxLength: 2 },
            ],
        },
        edit: { fields: [
            { key: 'name', label: 'Name', required: true }, { key: 'domain', label: 'Domain' },
            { key: 'contact_name', label: 'Contact' }, { key: 'email', label: 'Email', type: 'email' },
            { key: 'phone', label: 'Phone' }, { key: 'address', label: 'Address' },
            { key: 'city', label: 'City' }, { key: 'state', label: 'State', maxLength: 2 },
            { key: 'zip', label: 'Zip' }, { key: 'active', label: 'Active', type: 'checkbox' },
        ] },
    },
};

/** Top-level groups and their sub-tabs. */
export const GROUPS = {
    people: { title: 'People', tabs: [
        { key: 'staff', label: 'Staff', entity: 'people' },
        { key: 'vendors', label: 'Vendors', entity: 'vendors' },
        { key: 'onboarding', label: 'Onboarding', view: 'onboarding' },
    ] },
    assets: { title: 'Assets', tabs: [
        { key: 'devices', label: 'Devices', entity: 'devices' },
        { key: 'locations', label: 'Locations', entity: 'locations' },
        { key: 'rooms', label: 'Rooms', entity: 'rooms' },
        { key: 'onboard', label: 'Onboard', view: 'asset-onboard' },
    ] },
    tasks: { title: 'Tasks', tabs: [{ key: 'tasks', label: 'Tasks', view: 'tasks' }] },
    docs: { title: 'Docs', tabs: [{ key: 'docs', label: 'Docs', view: 'docs' }] },
};
