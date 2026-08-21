import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Archive,
    ArrowUpCircle,
    BadgeCheck,
    BarChart3,
    BookMarked,
    BookOpen,
    Briefcase,
    Building,
    CalendarDays,
    ClipboardList,
    Compass,
    Database,
    FileDiff,
    FileText,
    FileWarning,
    Fingerprint,
    Flag,
    FlaskConical,
    FolderOpen,
    Gauge,
    Globe,
    Hammer,
    Handshake,
    History,
    Inbox,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    LayoutTemplate,
    Leaf,
    Library,
    Link2,
    ListTodo,
    Lock,
    Map,
    Megaphone,
    MessageCircle,
    MessageSquare,
    Network,
    Package,
    Palette,
    PieChart,
    Plug,
    Route,
    Scale,
    ScrollText,
    Search,
    Send,
    Settings,
    Shield,
    ShieldAlert,
    ShieldCheck,
    ShieldPlus,
    Siren,
    Sparkles,
    Split,
    Target,
    Trash2,
    TrendingUp,
    Upload,
    Users,
    Wrench,
} from 'lucide-react';

/**
 * The single source of truth for the sidebar. The layout renders this array
 * and nothing else — no hardcoded nav items anywhere.
 *
 * Every entry is a group `{ id, label, icon, children }`. A group whose only
 * child survives gating renders that child directly at top level, which is
 * also how the ungrouped Dashboard and My Tasks entries are modelled.
 *
 * Child gating mirrors the server: `feature` must be in the shared features
 * list, `allowedRoles` (any-of) against auth.roles, `permission` against
 * auth.permissions. Absent keys mean "everyone".
 */

// Shorthand for the role lists that recur across the register.
const LEADERSHIP = ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'];
const LEADERSHIP_AND_OWNERS = [...LEADERSHIP, 'Control Owner'];
const CONTROL_FUNCTION = ['System Administrator', 'Control Function Head', 'Control Officer'];
const ADMINS = ['System Administrator', 'Control Function Head'];

const NAVIGATION = [
    {
        id: 'dashboard',
        label: 'Dashboard',
        icon: LayoutDashboard,
        children: [
            { label: 'Dashboard', to: 'dashboard', match: '/dashboard', icon: LayoutDashboard },
        ],
    },
    {
        // The mobile-first "what do I owe" view (Phase 15.2). Every role has
        // tasks, so it stays ungrouped and ungated — one tap from anywhere.
        id: 'my-tasks',
        label: 'My Tasks',
        icon: ListTodo,
        children: [
            { label: 'My Tasks', to: 'tasks.mine', match: '/my-tasks', icon: ListTodo },
        ],
    },
    {
        // CR2-A: the front door for a control function — the three
        // sub-units and the control universe. Sits above the flat
        // Controls library by design.
        id: 'control-structure',
        label: 'Control Structure',
        icon: Network,
        children: [
            { label: 'Control Structure', to: 'control-structure.index', match: '/control-structure', icon: Network, feature: 'control-structure', permission: 'view control-structure' },
        ],
    },
    {
        id: 'controls',
        label: 'Controls',
        icon: ShieldCheck,
        children: [
            { label: 'Control Library', to: 'controls.index', match: '/controls', icon: BookOpen },
            { label: 'Control Testing', to: 'test-instances.index', match: '/test-instances', icon: FlaskConical },
            { label: 'Test Scripts', to: 'test-scripts.index', match: '/test-scripts', icon: ClipboardList, allowedRoles: CONTROL_FUNCTION },
            { label: 'Spot Checks', to: 'spot-checks.index', match: '/spot-checks', icon: Search, allowedRoles: CONTROL_FUNCTION },
            { label: 'Campaigns', to: 'csa-campaigns.index', match: '/csa-campaigns', icon: Megaphone, feature: 'csa' },
            { label: 'Attestations', to: 'attestations.index', match: '/attestations', icon: BadgeCheck, feature: 'attestations' },
            { label: 'Continuous Monitoring', to: 'monitoring.dashboard', match: '/monitoring', icon: Activity, feature: 'continuous-monitoring' },
            { label: 'Segregation of Duties', to: 'sod.violations', match: '/sod', icon: Split, feature: 'sod-analysis' },
        ],
    },
    {
        id: 'risk',
        label: 'Risk',
        icon: AlertTriangle,
        children: [
            { label: 'Risk Register', to: 'risks.index', match: '/risks', icon: ShieldAlert, allowedRoles: LEADERSHIP },
            { label: 'Risk Heatmap', to: 'risks.heatmap', match: '/risks/heatmap', icon: LayoutGrid, feature: 'risk-heatmap', allowedRoles: LEADERSHIP },
            { label: 'Risk Appetite', to: 'appetite.index', match: '/risk-appetite', icon: Gauge, feature: 'risk-appetite', allowedRoles: LEADERSHIP },
            { label: 'Treatment Plans', to: 'treatments.index', match: '/treatments', icon: Wrench, feature: 'risk-treatments', allowedRoles: LEADERSHIP_AND_OWNERS },
            { label: 'Key Indicators', to: 'metrics.index', match: '/metrics', icon: TrendingUp, feature: 'metrics' },
            { label: 'Incidents', to: 'incidents.index', match: '/incidents', icon: Siren, feature: 'incidents' },
            { label: 'Third Parties', to: 'vendors.index', match: '/vendors', icon: Handshake, feature: 'vendors', allowedRoles: LEADERSHIP_AND_OWNERS },
            { label: 'Objectives', to: 'objectives.index', match: '/objectives', icon: Target, feature: 'objectives', allowedRoles: LEADERSHIP_AND_OWNERS },
            { label: 'Strategy Map', to: 'strategy.map', match: '/strategy/map', icon: Map, feature: 'objectives', allowedRoles: LEADERSHIP },
        ],
    },
    {
        id: 'exceptions',
        label: 'Exceptions & Issues',
        icon: FileWarning,
        children: [
            { label: 'Exceptions', to: 'exceptions.index', match: '/exceptions', icon: AlertCircle },
            { label: 'Exception Manager', to: 'exception-manager.index', match: '/exception-manager', icon: Inbox, feature: 'exception-manager' },
            { label: 'Exception Routing', to: 'admin.exception-routing', match: '/admin/exception-routing', icon: Route, feature: 'exception-manager', allowedRoles: ADMINS },
            { label: 'Escalation Matrix', to: 'admin.escalation-matrix', match: '/admin/escalation-matrix', icon: ArrowUpCircle, allowedRoles: ADMINS },
            { label: 'Compensating Controls', to: 'compensating-controls.index', match: '/compensating-controls', icon: ShieldPlus },
            { label: 'Improvements', to: 'improvements.index', match: '/improvements', icon: Hammer, feature: 'improvements' },
            { label: 'Complaints', to: 'complaints.index', match: '/complaints', icon: MessageSquare, feature: 'complaints' },
            { label: 'Cases', to: 'cases.index', match: '/cases', icon: Briefcase, feature: 'cases' },
            // CR — the reveal approver's queue; visible only to holders of
            // the approve permission, including standalone approvers who
            // hold no case permission at all.
            { label: 'Reveal Approvals', to: 'speak-up.reveal-requests', match: '/speak-up/reveal-requests', icon: KeyRound, feature: 'cases', permission: 'speak_up.metadata.approve_reveal' },
        ],
    },
    {
        id: 'compliance',
        label: 'Compliance',
        icon: Scale,
        children: [
            { label: 'Obligation Register', to: 'obligations.index', match: '/obligations', icon: BookMarked, feature: 'obligations' },
            { label: 'Compliance Calendar', to: 'obligations.calendar', match: '/obligations/calendar', icon: CalendarDays, feature: 'obligations' },
            { label: 'Regulatory Changes', to: 'regulatory-changes.index', match: '/regulatory-changes', icon: FileDiff, feature: 'regulatory-changes', allowedRoles: LEADERSHIP },
            { label: 'Frameworks', to: 'frameworks.index', match: '/frameworks', icon: Library, feature: 'frameworks' },
            { label: 'Policies', to: 'policies.index', match: '/policies', icon: ScrollText, feature: 'policies' },
            { label: 'Submissions', to: 'submissions.index', match: '/submissions', icon: Send, feature: 'submission-packs', allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer'] },
            { label: 'Data Residency', to: 'residency.index', match: '/residency', icon: Globe, feature: 'residency', allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer'] },
            { label: 'Sustainability', to: 'sustainability.index', match: '/sustainability', icon: Leaf, feature: 'sustainability', allowedRoles: LEADERSHIP_AND_OWNERS },
        ],
    },
    {
        id: 'evidence',
        label: 'Evidence',
        icon: Archive,
        children: [
            { label: 'Documents', to: 'documents.index', match: '/documents', icon: FolderOpen, feature: 'documents' },
            { label: 'Evidence Disposal', to: 'admin.evidence-disposal', match: '/admin/evidence-disposal', icon: Trash2, allowedRoles: ADMINS },
        ],
    },
    {
        id: 'reporting',
        label: 'Reporting',
        icon: BarChart3,
        children: [
            { label: 'Group Dashboard', to: 'group.dashboard', match: '/group', icon: PieChart, feature: 'entities', allowedRoles: LEADERSHIP },
            { label: 'Dashboards', to: 'dashboards.index', match: '/dashboards', icon: LayoutTemplate, feature: 'dashboard-builder' },
            { label: 'Report Library', to: 'reports.library', match: '/report-library', icon: FileText, feature: 'report-designer', allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer'] },
            { label: 'Assurance Map', to: 'assurance.map', match: '/assurance', icon: Compass, feature: 'assurance', allowedRoles: LEADERSHIP },
        ],
    },
    {
        id: 'data',
        label: 'Data & Integrations',
        icon: Plug,
        children: [
            { label: 'Data Sources', to: 'admin.data-sources.index', match: '/admin/data-sources', icon: Database, feature: 'data-sources', allowedRoles: ADMINS },
            { label: 'Integrations', to: 'admin.integrations', match: '/admin/integrations', icon: Link2, allowedRoles: ADMINS },
            { label: 'Bulk Import', to: 'admin.import', match: '/admin/import', icon: Upload, feature: 'bulk-import', allowedRoles: ADMINS },
            { label: 'Content Packs', to: 'admin.content-packs', match: '/admin/content-packs', icon: Package, feature: 'content-packs', allowedRoles: ADMINS },
            { label: 'AI Governance', to: 'admin.ai.index', match: '/admin/ai', icon: Sparkles, feature: 'ai-governance', allowedRoles: ADMINS },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        icon: Settings,
        children: [
            { label: 'Users', to: 'admin.users.index', match: '/admin/users', icon: Users, permission: 'manage users' },
            { label: 'Roles & Permissions', to: 'admin.roles', match: '/admin/roles', icon: KeyRound, allowedRoles: ['System Administrator'] },
            { label: 'Entities', to: 'entities.index', match: '/entities', icon: Building, feature: 'entities', allowedRoles: LEADERSHIP },
            { label: 'Single Sign-On', to: 'admin.sso', match: '/admin/sso', icon: Fingerprint, feature: 'sso', allowedRoles: ['System Administrator'] },
            { label: 'Security Policy', to: 'admin.security', match: '/admin/security', icon: Lock, allowedRoles: ['System Administrator'] },
            { label: 'Speak Up', to: 'admin.speak-up', match: '/admin/speak-up', icon: Megaphone, feature: 'whistleblowing', allowedRoles: ['System Administrator'] },
            { label: 'Branding', to: 'admin.branding', match: '/admin/branding', icon: Palette, feature: 'branding', allowedRoles: ['System Administrator'] },
            { label: 'Feature Flags', to: 'admin.feature-flags', match: '/admin/feature-flags', icon: Flag, allowedRoles: ['System Administrator'] },
            { label: 'Messaging', to: 'admin.messaging', match: '/admin/messaging', icon: MessageCircle, allowedRoles: ADMINS },
            { label: 'Activity Log', to: 'settings.activity-log', match: '/settings/activity-log', icon: History, feature: 'audit-log-ui', permission: 'view audit log' },
        ],
    },
];

export default NAVIGATION;

/** Apply the same gating the server applies, then drop empty groups. */
export function visibleNavigation({ roles = [], permissions = [], features = [] }) {
    // An entry whose route is not registered (feature branch ahead of the
    // backend, route behind a disabled module) is silently dropped rather
    // than letting route() throw and blank the whole layout.
    const routeExists = (name) => {
        try {
            return typeof route === 'function' && route().has(name);
        } catch {
            return false;
        }
    };

    const allowed = (item) =>
        (!item.allowedRoles || item.allowedRoles.some((role) => roles.includes(role))) &&
        (!item.permission || permissions.includes(item.permission)) &&
        (!item.feature || features.includes(item.feature)) &&
        routeExists(item.to);

    return NAVIGATION.map((group) => ({
        ...group,
        children: group.children.filter(allowed),
    })).filter((group) => group.children.length > 0);
}
