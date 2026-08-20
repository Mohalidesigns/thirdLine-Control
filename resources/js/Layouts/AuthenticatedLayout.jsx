import AtlasChat from '@/Components/AtlasChat';
import CommandPalette from '@/Components/CommandPalette';
import ConnectionBanner from '@/Components/ConnectionBanner';
import FlashNotification from '@/Components/FlashNotification';
import Dropdown from '@/Components/Dropdown';
import { visibleNavigation } from '@/config/navigation';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';

// Scroll offset is ephemeral, so it lives in sessionStorage; the collapsed
// state and the per-group accordion state are lasting preferences, so they
// live in localStorage.
const NAV_SCROLL_KEY = 'sidebar-nav-scroll';
const SIDEBAR_COLLAPSED_KEY = 'sidebar-collapsed';
const SIDEBAR_GROUPS_KEY = 'sidebar-groups';

function readStored(storage, key) {
    try {
        return window[storage].getItem(key);
    } catch {
        return null;
    }
}

function writeStored(storage, key, value) {
    try {
        window[storage].setItem(key, value);
    } catch {
        // Storage disabled — the sidebar just falls back to its defaults.
    }
}

function readGroupState() {
    try {
        const parsed = JSON.parse(readStored('localStorage', SIDEBAR_GROUPS_KEY) ?? '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}

/**
 * The layout is not a persistent Inertia layout, so it remounts on every visit
 * and the scrollable nav would otherwise snap back to the top each time. Keep
 * the scroll offset in session storage and restore it before the browser paints.
 */
function SidebarNav({ collapsed, children }) {
    const navRef = useRef(null);

    useLayoutEffect(() => {
        const nav = navRef.current;
        if (!nav) {
            return undefined;
        }

        const saved = readStored('sessionStorage', NAV_SCROLL_KEY);
        if (saved !== null) {
            nav.scrollTop = Number(saved);
        } else {
            // First visit of the session: bring the current page into view rather
            // than leaving a deep-linked item scrolled off the bottom.
            const active = nav.querySelector('[data-active="true"]');
            if (active) {
                const offset = active.getBoundingClientRect().top - nav.getBoundingClientRect().top;
                nav.scrollTop += offset - (nav.clientHeight - active.offsetHeight) / 2;
            }
        }

        const onScroll = () => writeStored('sessionStorage', NAV_SCROLL_KEY, String(nav.scrollTop));
        nav.addEventListener('scroll', onScroll, { passive: true });
        return () => nav.removeEventListener('scroll', onScroll);
    }, []);

    // Rail mode keeps overflow visible so the flyout popovers are not
    // clipped by the scroll container — ten icon buttons never overflow.
    return (
        <nav
            ref={navRef}
            aria-label="Primary"
            className={`sidebar-scroll flex-1 space-y-1 px-2 py-4 ${collapsed ? 'overflow-visible' : 'overflow-y-auto'}`}
        >
            {children}
        </nav>
    );
}

/** Move focus with arrow keys among the focusable rows of one container. */
function moveFocus(event) {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
        return;
    }
    const container = event.currentTarget;
    const rows = [...container.querySelectorAll('a[href], button')];
    const index = rows.indexOf(document.activeElement);
    if (index === -1) {
        return;
    }
    event.preventDefault();
    const next = rows[index + (event.key === 'ArrowDown' ? 1 : -1)];
    next?.focus();
}

function SidebarLink({ item, active, collapsed, topLevel = false }) {
    const Icon = item.icon;
    return (
        <Link
            href={route(item.to)}
            title={collapsed ? item.label : undefined}
            data-active={active}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-3 rounded-lg text-sm font-medium transition-colors duration-200 ${
                collapsed ? 'justify-center px-2 py-2.5' : `py-2 ${topLevel ? 'px-3' : 'ps-[2.375rem] pe-3'}`
            } ${
                active
                    ? 'border-l-[3px] border-[var(--color-accent)] bg-white/10 text-white'
                    : 'border-l-[3px] border-transparent text-white/70 hover:bg-white/5 hover:text-white'
            }`}
        >
            {(collapsed || topLevel) && (
                <Icon className={`h-5 w-5 shrink-0 ${active ? 'text-white' : 'text-white/60'}`} strokeWidth={1.7} aria-hidden="true" />
            )}
            {!collapsed && (
                <span className="flex min-w-0 flex-1 items-center gap-2 truncate">
                    {!topLevel && (
                        <Icon className={`h-4 w-4 shrink-0 ${active ? 'text-white' : 'text-white/50'}`} strokeWidth={1.7} aria-hidden="true" />
                    )}
                    <span className="truncate">{item.label}</span>
                    {item.badge != null && (
                        <span className="ms-auto rounded-full bg-white/15 px-1.5 text-[10px] font-semibold">{item.badge}</span>
                    )}
                </span>
            )}
        </Link>
    );
}

function SidebarGroup({ group, activeItem, open, onToggle, collapsed }) {
    const [flyout, setFlyout] = useState(false);
    const GroupIcon = group.icon;
    const containsActive = group.children.includes(activeItem);
    const panelId = `sidebar-group-${group.id}`;

    if (collapsed) {
        // Rail mode: the header is an icon-only button whose children open in
        // a flyout popover on hover or click.
        return (
            <div className="relative" onMouseEnter={() => setFlyout(true)} onMouseLeave={() => setFlyout(false)}>
                <button
                    type="button"
                    title={group.label}
                    aria-haspopup="true"
                    aria-expanded={flyout}
                    onClick={() => setFlyout((value) => !value)}
                    className={`flex w-full items-center justify-center rounded-lg px-2 py-2.5 transition-colors ${
                        containsActive ? 'bg-white/10 text-white' : 'text-white/60 hover:bg-white/5 hover:text-white'
                    }`}
                >
                    <GroupIcon className="h-5 w-5" strokeWidth={1.7} aria-hidden="true" />
                </button>
                {flyout && (
                    <div
                        className="absolute start-full top-0 z-50 ms-2 w-60 rounded-xl border border-white/10 bg-[var(--color-primary)] p-2 shadow-xl"
                        onKeyDown={moveFocus}
                    >
                        <p className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-widest text-white/40">
                            {group.label}
                        </p>
                        {group.children.map((item) => (
                            <SidebarLink key={item.to} item={item} active={item === activeItem} collapsed={false} topLevel />
                        ))}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div onKeyDown={moveFocus}>
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={onToggle}
                className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[11px] font-semibold uppercase tracking-widest transition-colors ${
                    containsActive && !open ? 'text-white' : 'text-white/50'
                } hover:bg-white/5 hover:text-white`}
            >
                <GroupIcon className="h-4 w-4 shrink-0" strokeWidth={1.7} aria-hidden="true" />
                <span className="flex-1 truncate text-start">{group.label}</span>
                <ChevronDown
                    className={`h-3.5 w-3.5 shrink-0 transition-transform duration-200 ${open ? '' : '-rotate-90'}`}
                    strokeWidth={2}
                    aria-hidden="true"
                />
            </button>
            {open && (
                <div id={panelId} className="space-y-0.5 pb-1">
                    {group.children.map((item) => (
                        <SidebarLink key={item.to} item={item} active={item === activeItem} collapsed={false} />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { props: { auth, unreadNotifications, branding, features = [], lowBandwidth }, url } = usePage();
    // Read synchronously so a collapsed sidebar never flashes open on remount.
    const [collapsed, setCollapsed] = useState(() => readStored('localStorage', SIDEBAR_COLLAPSED_KEY) === '1');
    const [mobileOpen, setMobileOpen] = useState(false);

    const userRoles = auth?.roles ?? [];
    const groups = visibleNavigation({
        roles: userRoles,
        permissions: auth?.permissions ?? [],
        features,
    });

    // usePage().url is the path + query string of the current Inertia visit.
    // Match on segment boundaries so /risks never swallows /risks/heatmap, and
    // keep only the longest match so the deepest item is the one highlighted.
    const currentPath = (url ?? '/').split(/[?#]/)[0];
    const matches = (item) => currentPath === item.match || currentPath.startsWith(`${item.match}/`);
    const activeItem = groups
        .flatMap((group) => group.children)
        .reduce((best, item) => (matches(item) && (!best || item.match.length > best.match.length) ? item : best), null);
    const activeGroup = groups.find((group) => group.children.includes(activeItem));

    // Stored accordion state wins, except that the group holding the current
    // page always opens on mount — seeing where you are beats a stale collapse.
    // With nothing stored, only the active group is expanded.
    const [groupState, setGroupState] = useState(() => {
        // The group holding the current page always opens on mount — seeing
        // where you are beats a stale collapse — so its stored entry is
        // dropped before first paint; every other group keeps its state.
        const stored = readGroupState();
        if (activeGroup) {
            delete stored[activeGroup.id];
        }
        return stored;
    });
    const effectiveOpen = (group) =>
        group.id in groupState ? groupState[group.id] === true : group === activeGroup;
    const toggleGroup = (group) => {
        const next = { ...groupState, [group.id]: !effectiveOpen(group) };
        setGroupState(next);
        writeStored('localStorage', SIDEBAR_GROUPS_KEY, JSON.stringify(next));
    };

    const sidebarWidth = collapsed ? 72 : 260;

    const renderNav = (isCollapsed) =>
        groups.map((group) =>
            group.children.length === 1 ? (
                // A one-item accordion is just a link — promote the child.
                <SidebarLink
                    key={group.id}
                    item={group.children[0]}
                    active={group.children[0] === activeItem}
                    collapsed={isCollapsed}
                    topLevel
                />
            ) : (
                <SidebarGroup
                    key={group.id}
                    group={group}
                    activeItem={activeItem}
                    open={effectiveOpen(group)}
                    onToggle={() => toggleGroup(group)}
                    collapsed={isCollapsed}
                />
            ),
        );

    const sidebar = (isCollapsed) => (
        <div className="flex h-full flex-col bg-[var(--color-primary)] text-white">
            <div className={`flex h-16 shrink-0 items-center border-b border-white/10 ${isCollapsed ? 'justify-center px-2' : 'px-5'}`}>
                <Link href={route('dashboard')} className="flex items-center gap-2.5">
                    {branding?.logo_dark_url || branding?.logo_url ? (
                        <img
                            src={branding.logo_dark_url ?? branding.logo_url}
                            alt={branding?.product_name ?? 'SecondLine'}
                            className={`shrink-0 object-contain ${isCollapsed ? 'h-9 w-9' : 'max-h-9 max-w-[180px]'}`}
                        />
                    ) : (
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--color-accent)] font-bold text-[var(--color-primary)]">
                            SL
                        </div>
                    )}
                    {!isCollapsed && (
                        <div className="leading-tight">
                            <p className="text-sm font-bold tracking-wide">{branding?.product_name ?? 'SecondLine'}</p>
                            {!branding?.product_name && (
                                <p className="text-[10px] uppercase tracking-widest text-white/50">Control Solution</p>
                            )}
                        </div>
                    )}
                </Link>
            </div>

            <SidebarNav collapsed={isCollapsed}>{renderNav(isCollapsed)}</SidebarNav>

            <button
                type="button"
                onClick={() => {
                    const next = !collapsed;
                    setCollapsed(next);
                    writeStored('localStorage', SIDEBAR_COLLAPSED_KEY, next ? '1' : '0');
                }}
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
                {sidebar(collapsed)}
            </aside>

            {/* Mobile drawer — always the full-width variant */}
            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/50" onClick={() => setMobileOpen(false)} />
                    <aside className="fixed inset-y-0 start-0 w-[260px]">{sidebar(false)}</aside>
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
            {/* Atlas renders its own launcher and hides itself entirely when
                the feature is off or the user lacks 'use ai' (Phase 14). */}
            <AtlasChat />
        </div>
    );
}
