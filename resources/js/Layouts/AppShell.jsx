import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import BrandMark from '@/Components/BrandMark';
import ThemeToggle from '@/Components/ThemeToggle';

/**
 * AppShell: global header (row 1) + two-column body (row 2).
 * Header carries the tenant (company) switcher and the user menu.
 * Reusable across every entity screen.
 */
export default function AppShell({ nav = null, detail = null, active = null, footer = null }) {
    const { auth, tenant } = usePage().props;

    return (
        <div className="h-screen overflow-hidden bg-gray-100 dark:bg-gray-950 flex flex-col">
            {/* Row 1 — global header */}
            <header className="h-14 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between px-4 shadow-sm">
                <div className="flex items-center gap-6">
                    <Link href="/" className="flex items-center gap-2 font-bold text-xl tracking-tight">
                        <BrandMark className="h-6 w-6" />
                        <span className="flex items-baseline">
                            <span className="text-blue-600 dark:text-blue-400">Asset</span><span className="text-gray-900 dark:text-white">Most</span>
                        </span>
                    </Link>
                    <nav className="flex items-center gap-1 text-sm">
                        <HeaderLink href="/people" label="People" active={active === 'people'} />
                        <HeaderLink href="/assets" label="Assets" active={active === 'assets'} />
                        <HeaderLink href="/tasks" label="Tasks" active={active === 'tasks'} />
                        <HeaderLink href="/docs" label="Docs" active={active === 'docs'} />
                    </nav>
                </div>
                <div className="flex items-center gap-3 text-sm">
                    <CompanySwitcher tenant={tenant} />
                    <ThemeToggle />
                    <Link href="/settings" title="Settings"
                        className={`h-8 w-8 flex items-center justify-center rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 ${active === 'settings' ? 'text-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400'}`}>
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
                        </svg>
                    </Link>
                    <UserMenu user={auth?.user} />
                </div>
            </header>

            {/* Row 2 — two columns (list + detail), or full-width when there's no nav */}
            <div className="flex-1 flex overflow-hidden">
                {nav && (
                    <aside className="w-96 shrink-0 bg-gray-50 dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 overflow-hidden">
                        {nav}
                    </aside>
                )}
                <main className="flex-1 overflow-y-auto bg-white dark:bg-gray-950 p-6">
                    {detail}
                </main>
            </div>

            {/* Footer — status bar */}
            <footer className="h-8 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 flex items-center px-4 text-xs text-gray-500 dark:text-gray-400">
                {footer}
            </footer>
        </div>
    );
}

function CompanySwitcher({ tenant }) {
    const companies = tenant?.companies || [];
    const active = tenant?.activeId ?? '';
    // single-tenant edition has one company — nothing to switch
    if (tenant?.edition === 'single' || companies.length < 2) return null;

    const change = (e) => {
        router.post('/switch-company', { company_id: e.target.value || null }, { preserveScroll: true });
    };

    return (
        <select
            value={active}
            onChange={change}
            className="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm py-1.5 pr-8 text-gray-700 dark:text-gray-200 focus:border-blue-500 focus:ring-blue-500"
            title="Active company"
        >
            <option value="">All companies</option>
            {companies.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
            ))}
        </select>
    );
}

function UserMenu({ user }) {
    const [open, setOpen] = useState(false);
    if (!user) return null;
    return (
        <div className="relative">
            <button
                onClick={() => setOpen((o) => !o)}
                className="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-800"
            >
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-blue-600 text-xs font-medium text-white">
                    {(user.name?.[0] || '') + (user.last?.[0] || '')}
                </span>
                <span className="text-gray-700 dark:text-gray-200">{user.name} {user.last}</span>
                <svg className="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor"><path d="M5.5 7.5 10 12l4.5-4.5" stroke="currentColor" fill="none" strokeWidth="1.5"/></svg>
            </button>
            {open && (
                <div className="absolute right-0 mt-1 w-44 rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 py-1 shadow-lg z-10">
                    <div className="px-3 py-2 text-xs text-gray-400 border-b border-gray-100 dark:border-gray-700">
                        {user.email}<br />{user.role}
                    </div>
                    <Link href="/profile" className="block px-3 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">Profile</Link>
                    <Link href="/logout" method="post" as="button" className="block w-full text-left px-3 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">Log out</Link>
                </div>
            )}
        </div>
    );
}

function HeaderLink({ href, label, active = false }) {
    return (
        <Link href={href}
            className={`px-3 py-1.5 rounded-md ${active ? 'bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300 font-medium' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'}`}>
            {label}
        </Link>
    );
}
