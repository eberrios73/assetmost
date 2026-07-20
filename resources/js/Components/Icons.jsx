const base = 'h-5 w-5';

export function DeviceIcon() {
    return (<svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <rect x="3" y="4" width="18" height="12" rx="1" /><path d="M8 20h8M12 16v4" /></svg>);
}
export function PersonIcon() {
    return (<svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <circle cx="12" cy="8" r="3.5" /><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6" /></svg>);
}
export function VendorIcon() {
    return (<svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <rect x="3" y="7" width="18" height="13" rx="1" /><path d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2" /></svg>);
}
export function RoomIcon() {
    return (<svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <path d="M3 21V8l9-5 9 5v13M3 21h18M9 21v-6h6v6" /></svg>);
}
export function ClientIcon() {
    return (<svg className={base} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <path d="M3 21V5a1 1 0 011-1h9a1 1 0 011 1v16M14 21V9h6a1 1 0 011 1v11M7 8h3M7 12h3M7 16h3" /></svg>);
}

// --- small action icons (h-4) ---
const sm = 'h-4 w-4';
export function CopyIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 012-2h8" /></svg>);
}
export function KeyIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <circle cx="7.5" cy="15.5" r="4" /><path d="M10.5 12.5 20 3M17 6l2 2M14 9l2 2" /></svg>);
}
export function ChatIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <path d="M4 5h16a1 1 0 011 1v9a1 1 0 01-1 1H9l-4 4v-4H4a1 1 0 01-1-1V6a1 1 0 011-1z" /></svg>);
}
export function TrashIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6" /></svg>);
}
export function EditIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <path d="M4 20h4L18 10l-4-4L4 16v4zM13 5l4 4" /></svg>);
}
export function PlusIcon({ className = sm }) {
    return (<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path d="M12 5v14M5 12h14" /></svg>);
}
// Disclosure chevron: points right when closed, rotates down when open.
export function Chevron({ open = false, className = 'h-4 w-4' }) {
    return (<svg className={`${className} transition-transform ${open ? 'rotate-90' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.4">
        <path d="M9 6l6 6-6 6" strokeLinecap="round" strokeLinejoin="round" /></svg>);
}
export function EyeIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" /><circle cx="12" cy="12" r="2.8" /></svg>);
}
export function EyeOffIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <path d="M2 12s3.5-6.5 10-6.5c2 0 3.8.6 5.2 1.5M22 12s-3.5 6.5-10 6.5c-2 0-3.8-.6-5.2-1.5M4 20 20 4" /></svg>);
}
export function PrintIcon() {
    return (<svg className={sm} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
        <path d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-5a2 2 0 012-2h14a2 2 0 012 2v5a1 1 0 01-1 1h-2M6 14h12v7H6z" /></svg>);
}
export function DocIcon({ className = sm }) {
    return (<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <path d="M6 3h8l4 4v14a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z" /><path d="M14 3v4h4M8 13h8M8 17h6" /></svg>);
}
// template glyphs (Tabler-ish), replacing emoji
export function ClipboardIcon({ className = sm }) {
    return (<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" /><rect x="9" y="3" width="6" height="4" rx="1" /><path d="M9 12h6M9 16h4" /></svg>);
}
export function WrenchIcon({ className = sm }) {
    return (<svg className={className} viewBox="0 0 24 24" fill="currentColor"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z" /></svg>);
}
export function AlertIcon({ className = sm }) {
    return (<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
        <path d="M12 4l9 15.5H3z" /><path d="M12 10v4M12 17.5v.01" /></svg>);
}
