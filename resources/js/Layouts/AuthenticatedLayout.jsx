import ApplicationLogo from '@/Components/ApplicationLogo';
import AtlasChat from '@/Components/AtlasChat';
import CommandPalette from '@/Components/CommandPalette';
import ConnectionBanner from '@/Components/ConnectionBanner';
import FlashNotification from '@/Components/FlashNotification';
import ValidationNotification from '@/Components/ValidationNotification';
import Dropdown from '@/Components/Dropdown';
import { visibleNavigation } from '@/config/navigation';
import { Link, usePage } from '@inertiajs/react';
import { Bell, ChevronDown, ChevronRight, ChevronsLeft, Menu, Search } from 'lucide-react';
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
            className={`sidebar-scroll flex-1 space-y-1 px-3 py-4 ${collapsed ? 'overflow-visible' : 'overflow-y-auto'}`}
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

function SidebarLink({ item, active, collapsed, nested = false }) {
    const Icon = item.icon;
    return (
        <Link
            href={route(item.to)}
            title={collapsed ? item.label : undefined}
            data-active={active}
            aria-current={active ? 'page' : undefined}
            className={`sidebar-nav-item ${nested ? 'text-xs' : ''} ${collapsed ? 'justify-center px-2' : ''} ${active ? 'active' : ''}`}
        >
            {!nested && (
                <span className="flex-shrink-0">
                    <Icon className="h-5 w-5" aria-hidden="true" />
                </span>
            )}
            {!collapsed && <span className="truncate">{item.label}</span>}
            {!collapsed && item.badge != null && (
                <span className="ms-auto rounded-full bg-white/15 px-1.5 text-[10px] font-semibold">{item.badge}</span>
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
                    className={`sidebar-nav-item w-full justify-center px-2 ${containsActive ? 'active' : ''}`}
                >
                    <span className="flex-shrink-0">
                        <GroupIcon className="h-5 w-5" aria-hidden="true" />
                    </span>
                </button>
                {flyout && (
                    <div
                        className="absolute start-full top-0 z-50 ms-2 w-60 space-y-0.5 rounded-xl border border-white/10 bg-[var(--color-primary)] p-2 shadow-xl"
                        onKeyDown={moveFocus}
                    >
                        <p className="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-white/40">
                            {group.label}
                        </p>
                        {group.children.map((item) => (
                            <SidebarLink key={item.to} item={item} active={item === activeItem} collapsed={false} nested />
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
                className={`sidebar-nav-item w-full ${containsActive ? 'active' : ''}`}
            >
                <span className="flex-shrink-0">
                    <GroupIcon className="h-5 w-5" aria-hidden="true" />
                </span>
                <span className="flex-1 truncate text-left">{group.label}</span>
                <ChevronRight
                    className={`h-4 w-4 flex-shrink-0 transition-transform ${open ? 'rotate-90' : ''}`}
                    aria-hidden="true"
                />
            </button>
            {open && (
                <div id={panelId} className="mb-1 ml-4 mt-0.5 space-y-0.5 border-l border-white/10 pl-3">
                    {group.children.map((item) => (
                        <SidebarLink key={item.to} item={item} active={item === activeItem} collapsed={false} nested />
                    ))}
                </div>
            )}
        </div>
    );
}

// `header` is still passed by every page but no longer drawn in the top bar —
// ThirdLine puts the title only in the page header band (PageHeader).
export default function AuthenticatedLayout({ children }) {
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

    const toggleCollapsed = () => {
        const next = !collapsed;
        setCollapsed(next);
        writeStored('localStorage', SIDEBAR_COLLAPSED_KEY, next ? '1' : '0');
    };

    const initials = auth.user?.name?.split(' ').map((part) => part[0]).slice(0, 1).join('').toUpperCase();

    const sidebar = (isCollapsed) => (
        <div className="flex h-full flex-col bg-[var(--color-primary)] text-white">
            <div className={`flex h-16 flex-shrink-0 items-center border-b border-white/10 ${isCollapsed ? 'justify-center px-2' : 'px-4'}`}>
                <Link href={route('dashboard')} className="flex items-center gap-2.5">
                    {branding?.logo_dark_url || branding?.logo_url ? (
                        <>
                            <img
                                src={branding.logo_dark_url ?? branding.logo_url}
                                alt={branding?.product_name ?? 'SecondLine'}
                                className={`shrink-0 object-contain ${isCollapsed ? 'h-9 w-9' : 'h-11 max-w-[200px]'}`}
                            />
                            {!isCollapsed && branding?.product_name && (
                                <p className="text-sm font-bold leading-tight tracking-wide">{branding.product_name}</p>
                            )}
                        </>
                    ) : (
                        <ApplicationLogo tone="light" size="sm" wordmark={!isCollapsed} />
                    )}
                </Link>
            </div>

            <SidebarNav collapsed={isCollapsed}>{renderNav(isCollapsed)}</SidebarNav>

            <div className="hidden flex-shrink-0 space-y-1 border-t border-white/10 px-3 py-4 lg:block">
                <button
                    type="button"
                    onClick={toggleCollapsed}
                    title={collapsed ? 'Expand' : undefined}
                    className={`sidebar-nav-item w-full text-white/50 hover:text-white ${isCollapsed ? 'justify-center px-2' : ''}`}
                >
                    <ChevronsLeft className={`h-5 w-5 transition-transform ${collapsed ? 'rotate-180' : ''}`} aria-hidden="true" />
                    {!isCollapsed && <span>Collapse</span>}
                </button>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-[var(--color-bg)]">
            {/* Desktop sidebar */}
            <aside
                className="fixed inset-y-0 start-0 z-50 hidden transition-all duration-300 lg:block"
                style={{ width: sidebarWidth }}
            >
                {sidebar(collapsed)}
            </aside>

            {/* Mobile drawer — always the full-width variant */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div className="fixed inset-0 bg-black/50" onClick={() => setMobileOpen(false)} />
                    <aside className="fixed inset-y-0 start-0 w-[var(--sidebar-width)]">{sidebar(false)}</aside>
                </div>
            )}

            {/* Main column, offset for the fixed sidebar on desktop */}
            <div className="flex min-h-screen flex-col transition-all duration-300 lg:ps-[var(--offset)]" style={{ '--offset': `${sidebarWidth}px` }}>
                <header className="sticky top-0 z-30 h-16 border-b border-gray-200 bg-white">
                    <div className="flex h-full items-center justify-between px-4 sm:px-6">
                        <div className="flex items-center gap-4">
                            <button
                                type="button"
                                aria-label="Open navigation"
                                className="rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 lg:hidden"
                                onClick={() => setMobileOpen(true)}
                            >
                                <Menu className="h-6 w-6" aria-hidden="true" />
                            </button>
                            {features.includes('global-search') && (
                                <div className="hidden items-center sm:flex">
                                    {/* Reads as ThirdLine's search field; opens the command palette. */}
                                    <button
                                        type="button"
                                        onClick={() => window.dispatchEvent(new CustomEvent('open-command-palette'))}
                                        className="relative flex w-80 items-center rounded-lg border border-gray-200 bg-gray-50 py-2 pl-10 pr-4 text-left text-sm text-gray-400 transition-colors hover:border-gray-300 focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20"
                                    >
                                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                                        <span className="flex-1 truncate">Search controls, risks, issues...</span>
                                        <kbd className="hidden rounded border border-gray-200 bg-white px-1.5 text-[10px] text-gray-400 md:inline">⌘K</kbd>
                                    </button>
                                </div>
                            )}
                        </div>

                        <div className="flex items-center gap-3">
                            <Link
                                href={route('notifications.index')}
                                aria-label="Notifications"
                                className="relative rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700"
                            >
                                <Bell className={`h-5 w-5 ${unreadNotifications > 0 ? 'animate-bell-ring' : ''}`} aria-hidden="true" />
                                {unreadNotifications > 0 && (
                                    <span className="absolute -right-0.5 -top-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-[var(--color-error)] text-[10px] font-bold text-white">
                                        {unreadNotifications > 9 ? '9+' : unreadNotifications}
                                    </span>
                                )}
                            </Link>

                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        className="flex items-center gap-3 rounded-lg p-1.5 transition-colors hover:bg-gray-100"
                                    >
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--color-primary)] text-sm font-semibold text-white">
                                            {initials}
                                        </div>
                                        <div className="hidden text-left sm:block">
                                            <div className="text-sm font-medium text-gray-700">{auth.user?.name}</div>
                                            <div className="text-xs text-gray-500">{userRoles[0] ?? ''}</div>
                                        </div>
                                        <ChevronDown className="hidden h-4 w-4 text-gray-400 sm:block" aria-hidden="true" />
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content width="48">
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
                                        Log Out
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                </header>

                <main className="flex-1 p-4 sm:p-6">{children}</main>
            </div>

            <FlashNotification />
            <ValidationNotification />
            <CommandPalette />
            <ConnectionBanner />
            {/* Atlas renders its own launcher and hides itself entirely when
                the feature is off or the user lacks 'use ai' (Phase 14). */}
            <AtlasChat />
        </div>
    );
}
