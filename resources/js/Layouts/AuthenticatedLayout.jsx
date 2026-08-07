import CommandPalette from '@/Components/CommandPalette';
import ConnectionBanner from '@/Components/ConnectionBanner';
import FlashNotification from '@/Components/FlashNotification';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

const NAV_ITEMS = [
    {
        label: 'Dashboard',
        route: 'dashboard',
        match: 'dashboard',
        icon: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
    },
    {
        label: 'Control Library',
        route: 'controls.index',
        match: '/controls',
        icon: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
    },
    {
        label: 'Risk Register',
        route: 'risks.index',
        match: '/risks',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'],
        icon: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
    },
    {
        label: 'Test Scripts',
        route: 'test-scripts.index',
        match: '/test-scripts',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer'],
        icon: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
    },
    {
        label: 'Control Testing',
        route: 'test-instances.index',
        match: '/test-instances',
        icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        label: 'Exceptions',
        route: 'exceptions.index',
        match: '/exceptions',
        icon: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
    },
    {
        label: 'Compensating Controls',
        route: 'compensating-controls.index',
        match: '/compensating-controls',
        icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    },
    {
        label: 'Spot Checks',
        route: 'spot-checks.index',
        match: '/spot-checks',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer'],
        icon: 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
    },
    {
        label: 'Escalation Matrix',
        route: 'admin.escalation-matrix',
        match: '/admin/escalation-matrix',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        icon: 'M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941',
    },
    {
        label: 'Evidence Disposal',
        route: 'admin.evidence-disposal',
        match: '/admin/evidence-disposal',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        icon: 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
    },
    {
        label: 'Integrations',
        route: 'admin.integrations',
        match: '/admin/integrations',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        icon: 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244',
    },
    {
        label: 'Audit Log',
        route: 'admin.audit-log',
        match: '/admin/audit-log',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        feature: 'audit-log-ui',
        icon: 'M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5zM9 5.25h10.5M9 11.25h10.5M9 17.25h10.5M4.5 5.25h.008v.008H4.5V5.25zm0 6h.008v.008H4.5v-.008zm0 6h.008v.008H4.5v-.008z',
    },
    {
        label: 'Single Sign-On',
        route: 'admin.sso',
        match: '/admin/sso',
        allowedRoles: ['System Administrator'],
        feature: 'sso',
        icon: 'M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z',
    },
    {
        label: 'Security Policy',
        route: 'admin.security',
        match: '/admin/security',
        allowedRoles: ['System Administrator'],
        icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    },
    {
        label: 'Branding',
        route: 'admin.branding',
        match: '/admin/branding',
        allowedRoles: ['System Administrator'],
        feature: 'branding',
        icon: 'M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42',
    },
    {
        label: 'Feature Flags',
        route: 'admin.feature-flags',
        match: '/admin/feature-flags',
        allowedRoles: ['System Administrator'],
        icon: 'M3 3v1.5M3 21v-6m0 0l2.77-.693a9 9 0 016.208.682l.108.054a9 9 0 006.086.71l3.114-.732a48.524 48.524 0 01-.005-10.499l-3.11.732a9 9 0 01-6.085-.711l-.108-.054a9 9 0 00-6.208-.682L3 4.5M3 15V4.5',
    },
];

function NavIcon({ d }) {
    return (
        <svg className="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth={1.7} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d={d} />
        </svg>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { auth, unreadNotifications, branding, features = [], lowBandwidth } = usePage().props;
    const [collapsed, setCollapsed] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);

    const userRoles = auth?.roles ?? [];
    const visibleItems = NAV_ITEMS.filter(
        (item) =>
            (!item.allowedRoles || item.allowedRoles.some((role) => userRoles.includes(role))) &&
            (!item.feature || features.includes(item.feature)),
    );

    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '/';
    const isActive = (item) =>
        item.match === 'dashboard' ? currentPath.startsWith('/dashboard') : currentPath.startsWith(item.match);

    const sidebarWidth = collapsed ? 72 : 260;

    const sidebar = (
        <div className="flex h-full flex-col bg-[var(--color-primary)] text-white">
            <div className={`flex h-16 shrink-0 items-center border-b border-white/10 ${collapsed ? 'justify-center px-2' : 'px-5'}`}>
                <Link href={route('dashboard')} className="flex items-center gap-2.5">
                    {branding?.logo_dark_url || branding?.logo_url ? (
                        <img
                            src={branding.logo_dark_url ?? branding.logo_url}
                            alt={branding?.product_name ?? 'SecondLine'}
                            className={`shrink-0 object-contain ${collapsed ? 'h-9 w-9' : 'max-h-9 max-w-[180px]'}`}
                        />
                    ) : (
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--color-accent)] font-bold text-[var(--color-primary)]">
                            SL
                        </div>
                    )}
                    {!collapsed && (
                        <div className="leading-tight">
                            <p className="text-sm font-bold tracking-wide">{branding?.product_name ?? 'SecondLine'}</p>
                            {!branding?.product_name && (
                                <p className="text-[10px] uppercase tracking-widest text-white/50">Control Solution</p>
                            )}
                        </div>
                    )}
                </Link>
            </div>

            <nav className="sidebar-scroll flex-1 space-y-0.5 overflow-y-auto px-2 py-4">
                {visibleItems.map((item) => {
                    const active = isActive(item);
                    return (
                        <Link
                            key={item.route}
                            href={route(item.route)}
                            title={collapsed ? item.label : undefined}
                            className={`flex items-center gap-3 rounded-lg py-2.5 text-sm font-medium transition-colors duration-200 ${
                                collapsed ? 'justify-center px-2' : 'px-3'
                            } ${
                                active
                                    ? 'border-l-[3px] border-[var(--color-accent)] bg-white/10 text-white'
                                    : 'border-l-[3px] border-transparent text-white/70 hover:bg-white/5 hover:text-white'
                            }`}
                        >
                            <NavIcon d={item.icon} />
                            {!collapsed && <span className="truncate">{item.label}</span>}
                        </Link>
                    );
                })}
            </nav>

            <button
                type="button"
                onClick={() => setCollapsed((c) => !c)}
                className="hidden shrink-0 items-center justify-center border-t border-white/10 py-3 text-white/60 transition-colors hover:text-white lg:flex"
            >
                <svg className={`h-5 w-5 transition-transform duration-200 ${collapsed ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" strokeWidth={1.7} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" />
                </svg>
            </button>
        </div>
    );

    return (
        <div className="min-h-screen bg-[var(--color-bg)]">
            {/* Desktop sidebar */}
            <aside
                className="fixed inset-y-0 start-0 z-30 hidden transition-all duration-200 lg:block"
                style={{ width: sidebarWidth }}
            >
                {sidebar}
            </aside>

            {/* Mobile drawer */}
            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/50" onClick={() => setMobileOpen(false)} />
                    <aside className="fixed inset-y-0 start-0 w-[260px]">{sidebar}</aside>
                </div>
            )}

            {/* Main column, offset for the fixed sidebar on desktop */}
            <div className="flex min-h-screen flex-col transition-all duration-200 lg:ps-[var(--offset)]" style={{ '--offset': `${sidebarWidth}px` }}>
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 shadow-sm sm:px-6">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            className="text-gray-500 hover:text-gray-700 lg:hidden"
                            onClick={() => setMobileOpen(true)}
                        >
                            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.7} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                            </svg>
                        </button>
                        {header && <h2 className="text-lg font-semibold text-[var(--color-text-primary)]">{header}</h2>}
                    </div>

                    <div className="flex items-center gap-3">
                        {features.includes('global-search') && (
                            <button
                                type="button"
                                onClick={() => window.dispatchEvent(new CustomEvent('open-command-palette'))}
                                className="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-400 transition-colors hover:border-gray-300 hover:text-gray-600"
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                                <span className="hidden sm:inline">Search</span>
                                <kbd className="hidden rounded border border-gray-200 px-1 text-[10px] sm:inline">⌘K</kbd>
                            </button>
                        )}
                        <Link
                            href={route('notifications.index')}
                            className="relative rounded-full p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-[var(--color-primary)]"
                        >
                            <svg className={`h-5 w-5 ${unreadNotifications > 0 ? 'animate-bell-ring' : ''}`} fill="none" viewBox="0 0 24 24" strokeWidth={1.7} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                            {unreadNotifications > 0 && (
                                <span className="absolute -end-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-[var(--color-error)] px-1 text-[10px] font-bold text-white">
                                    {unreadNotifications > 99 ? '99+' : unreadNotifications}
                                </span>
                            )}
                        </Link>

                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="flex items-center gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-gray-100"
                                >
                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--color-primary)] text-sm font-semibold text-white">
                                        {auth.user?.name?.split(' ').map((part) => part[0]).slice(0, 2).join('')}
                                    </div>
                                    <div className="hidden text-start sm:block">
                                        <p className="text-sm font-medium leading-tight text-[var(--color-text-primary)]">{auth.user?.name}</p>
                                        <p className="text-xs leading-tight text-[var(--color-text-secondary)]">{userRoles[0] ?? ''}</p>
                                    </div>
                                    <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content width="60">
                                <div className="border-b border-gray-100 px-4 py-3">
                                    <p className="text-sm font-medium text-[var(--color-text-primary)]">{auth.user?.name}</p>
                                    <p className="truncate text-xs text-[var(--color-text-secondary)]">{auth.user?.email}</p>
                                </div>
                                {features.includes('mfa') && (
                                    <Dropdown.Link href={route('mfa.setup')}>Security & MFA</Dropdown.Link>
                                )}
                                {features.includes('notification-preferences') && (
                                    <Dropdown.Link href={route('settings.notifications')}>
                                        Notification preferences
                                    </Dropdown.Link>
                                )}
                                {features.includes('low-bandwidth-mode') && (
                                    <Dropdown.Link href={route('settings.low-bandwidth')} method="post" as="button">
                                        {lowBandwidth ? 'Disable low-bandwidth mode' : 'Enable low-bandwidth mode'}
                                    </Dropdown.Link>
                                )}
                                <Dropdown.Link href={route('logout')} method="post" as="button">
                                    Log out
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">{children}</main>
            </div>

            <FlashNotification />
            <CommandPalette />
            <ConnectionBanner />
        </div>
    );
}
