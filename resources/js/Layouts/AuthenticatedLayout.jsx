import AtlasChat from '@/Components/AtlasChat';
import CommandPalette from '@/Components/CommandPalette';
import ConnectionBanner from '@/Components/ConnectionBanner';
import FlashNotification from '@/Components/FlashNotification';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';

const NAV_ITEMS = [
    {
        label: 'Dashboard',
        route: 'dashboard',
        match: 'dashboard',
        icon: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
    },
    {
        // Phase 15.2: the mobile-first "what do I owe" view — every role
        // has tasks, so no role gate.
        label: 'My Tasks',
        route: 'tasks.mine',
        match: '/my-tasks',
        icon: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
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
        label: 'Risk Heatmap',
        route: 'risks.heatmap',
        match: '/risks/heatmap',
        feature: 'risk-heatmap',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'],
        icon: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
    },
    {
        label: 'Risk Appetite',
        route: 'appetite.index',
        match: '/risk-appetite',
        feature: 'risk-appetite',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'],
        icon: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        label: 'Treatment Plans',
        route: 'treatments.index',
        match: '/treatments',
        feature: 'risk-treatments',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager', 'Control Owner'],
        icon: 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
    },
    {
        label: 'Key Indicators',
        route: 'metrics.index',
        match: '/metrics',
        feature: 'metrics',
        icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
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
        label: 'Documents',
        route: 'documents.index',
        match: '/documents',
        feature: 'documents',
        icon: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    },
    {
        label: 'Improvements',
        route: 'improvements.index',
        match: '/improvements',
        feature: 'improvements',
        icon: 'M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18',
    },
    {
        label: 'Attestations',
        route: 'attestations.index',
        match: '/attestations',
        feature: 'attestations',
        icon: 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z',
    },
    {
        label: 'Campaigns',
        route: 'csa-campaigns.index',
        match: '/csa-campaigns',
        feature: 'csa',
        icon: 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46',
    },
    {
        label: 'Policies',
        route: 'policies.index',
        match: '/policies',
        feature: 'policies',
        icon: 'M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25 2.25 0 002.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z',
    },
    {
        label: 'Incidents',
        route: 'incidents.index',
        match: '/incidents',
        feature: 'incidents',
        icon: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
    },
    {
        label: 'Complaints',
        route: 'complaints.index',
        match: '/complaints',
        feature: 'complaints',
        icon: 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z',
    },
    {
        label: 'Cases',
        route: 'cases.index',
        match: '/cases',
        feature: 'cases',
        icon: 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z',
    },
    {
        label: 'Continuous Monitoring',
        route: 'monitoring.dashboard',
        match: '/monitoring',
        feature: 'continuous-monitoring',
        icon: 'M2.25 13.5h3.86a2.25 2.25 0 002.012-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.218a2.25 2.25 0 012.013 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z',
    },
    {
        label: 'Segregation of Duties',
        route: 'sod.violations',
        match: '/sod',
        feature: 'sod-analysis',
        icon: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
    },
    {
        label: 'Frameworks',
        route: 'frameworks.index',
        match: '/frameworks',
        feature: 'frameworks',
        icon: 'M3.75 6A2.25 2.25 0 016 3.75h12A2.25 2.25 0 0120.25 6v12A2.25 2.25 0 0118 20.25H6A2.25 2.25 0 013.75 18V6zM7.5 8.25h9m-9 3.75h9m-9 3.75h5.25',
    },
    {
        label: 'Compliance Calendar',
        route: 'obligations.calendar',
        match: '/obligations/calendar',
        feature: 'obligations',
        icon: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
    },
    {
        label: 'Obligation Register',
        route: 'obligations.index',
        match: '/obligations',
        feature: 'obligations',
        icon: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    },
    {
        label: 'Regulatory Changes',
        route: 'regulatory-changes.index',
        match: '/regulatory-changes',
        feature: 'regulatory-changes',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'],
        icon: 'M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25 2.25 0 002.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z',
    },
    {
        label: 'Content Packs',
        route: 'admin.content-packs',
        match: '/admin/content-packs',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        feature: 'content-packs',
        icon: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
    },
    {
        label: 'Dashboards',
        route: 'dashboards.index',
        match: '/dashboards',
        feature: 'dashboard-builder',
        icon: 'M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5m.75-9l3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605',
    },
    {
        label: 'Report Library',
        route: 'reports.library',
        match: '/report-library',
        feature: 'report-designer',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer'],
        icon: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    },
    {
        label: 'Submissions',
        route: 'submissions.index',
        match: '/submissions',
        feature: 'submission-packs',
        allowedRoles: ['System Administrator', 'Control Function Head', 'Control Officer', 'Executive Viewer'],
        icon: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
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
        label: 'Bulk Import',
        route: 'admin.import',
        match: '/admin/import',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        feature: 'bulk-import',
        icon: 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5',
    },
    {
        label: 'Data Sources',
        route: 'admin.data-sources.index',
        match: '/admin/data-sources',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        feature: 'data-sources',
        icon: 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75',
    },
    {
        label: 'AI Governance',
        route: 'admin.ai.index',
        match: '/admin/ai',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        feature: 'ai-governance',
        icon: 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
    },
    {
        // Phase 15.3/15.4: template catalogue, delivery log, channel costs.
        label: 'Messaging',
        route: 'admin.messaging',
        match: '/admin/messaging',
        allowedRoles: ['System Administrator', 'Control Function Head'],
        icon: 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
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

// Scroll offset is ephemeral, so it lives in sessionStorage; the collapsed state
// is a lasting preference, so it lives in localStorage.
const NAV_SCROLL_KEY = 'sidebar-nav-scroll';
const SIDEBAR_COLLAPSED_KEY = 'sidebar-collapsed';

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

/**
 * The layout is not a persistent Inertia layout, so it remounts on every visit
 * and the scrollable nav would otherwise snap back to the top each time. Keep
 * the scroll offset in session storage and restore it before the browser paints.
 */
function SidebarNav({ children }) {
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

    return (
        <nav ref={navRef} className="sidebar-scroll flex-1 space-y-0.5 overflow-y-auto px-2 py-4">
            {children}
        </nav>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { props: { auth, unreadNotifications, branding, features = [], lowBandwidth }, url } = usePage();
    // Read synchronously so a collapsed sidebar never flashes open on remount.
    const [collapsed, setCollapsed] = useState(() => readStored('localStorage', SIDEBAR_COLLAPSED_KEY) === '1');
    const [mobileOpen, setMobileOpen] = useState(false);

    const userRoles = auth?.roles ?? [];
    const visibleItems = NAV_ITEMS.filter(
        (item) =>
            (!item.allowedRoles || item.allowedRoles.some((role) => userRoles.includes(role))) &&
            (!item.feature || features.includes(item.feature)),
    );

    // usePage().url is the path + query string of the current Inertia visit.
    const currentPath = (url ?? '/').split(/[?#]/)[0];
    const basePath = (item) => (item.match === 'dashboard' ? '/dashboard' : item.match);
    // Match on segment boundaries so /risks never swallows /risks/heatmap, and
    // keep only the longest match so the deepest item is the one highlighted.
    const matches = (item) => {
        const base = basePath(item);
        return currentPath === base || currentPath.startsWith(`${base}/`);
    };
    const activeItem = visibleItems.reduce(
        (best, item) => (matches(item) && (!best || basePath(item).length > basePath(best).length) ? item : best),
        null,
    );
    const isActive = (item) => item === activeItem;

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

            <SidebarNav>
                {visibleItems.map((item) => {
                    const active = isActive(item);
                    return (
                        <Link
                            key={item.route}
                            href={route(item.route)}
                            title={collapsed ? item.label : undefined}
                            data-active={active}
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
            </SidebarNav>

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
            {/* Atlas renders its own launcher and hides itself entirely when
                the feature is off or the user lacks 'use ai' (Phase 14). */}
            <AtlasChat />
        </div>
    );
}
