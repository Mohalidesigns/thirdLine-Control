import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Saved views (Phase 7.8): save the current filter set, pick a default
 * per resource, share with colleagues in the same role.
 */
function SavedViews({ resource, routeName, currentFilters }) {
    const [views, setViews] = useState([]);
    const [saving, setSaving] = useState(false);
    const [name, setName] = useState('');
    const [isShared, setIsShared] = useState(false);
    const [isDefault, setIsDefault] = useState(false);
    const appliedDefault = useRef(false);

    const load = () => {
        fetch(`${route('saved-views.index')}?resource=${encodeURIComponent(resource)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : { views: [] }))
            .then((json) => setViews(json.views ?? []));
    };

    useEffect(load, [resource]);

    // Apply the default view on a bare page load, once.
    useEffect(() => {
        if (appliedDefault.current || Object.keys(currentFilters).length > 0) return;
        const fallback = views.find((view) => view.is_default && view.is_mine);
        if (fallback && Object.keys(fallback.filters ?? {}).length > 0) {
            appliedDefault.current = true;
            router.get(route(routeName), fallback.filters, { preserveState: true, replace: true });
        }
    }, [views, currentFilters, routeName]);

    const hasFilters = Object.values(currentFilters).some((v) => v !== null && v !== '' && v !== undefined);

    const save = () => {
        if (!name.trim()) return;
        router.post(
            route('saved-views.store'),
            { resource, name: name.trim(), filters: currentFilters, is_shared: isShared, is_default: isDefault },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setSaving(false);
                    setName('');
                    load();
                },
            },
        );
    };

    return (
        <div className="filter-bar-group relative">
            <label className="filter-bar-label">Saved views</label>
            <div className="flex items-center gap-1.5">
                <select
                    className="filter-bar-select"
                    value=""
                    onChange={(e) => {
                        const view = views.find((v) => v.id === Number(e.target.value));
                        if (view) router.get(route(routeName), view.filters, { preserveState: true });
                    }}
                >
                    <option value="">{views.length ? 'Apply a view…' : 'No saved views'}</option>
                    {views.map((view) => (
                        <option key={view.id} value={view.id}>
                            {view.name}
                            {view.is_default && view.is_mine ? ' ★' : ''}
                            {view.is_shared && !view.is_mine ? ` (${view.owner})` : ''}
                        </option>
                    ))}
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        className="whitespace-nowrap text-sm font-medium text-[var(--color-primary)] underline-offset-2 hover:underline"
                        onClick={() => setSaving((s) => !s)}
                    >
                        Save view
                    </button>
                )}
            </div>

            {saving && (
                <div className="absolute end-0 top-full z-30 mt-1 w-64 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                    <input
                        type="text"
                        className="filter-bar-input mb-2 w-full"
                        placeholder="View name"
                        value={name}
                        autoFocus
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && save()}
                    />
                    <label className="mb-1 flex items-center gap-2 text-xs text-gray-600">
                        <input type="checkbox" className="rounded border-gray-300" checked={isDefault} onChange={(e) => setIsDefault(e.target.checked)} />
                        Make my default for this page
                    </label>
                    <label className="mb-2 flex items-center gap-2 text-xs text-gray-600">
                        <input type="checkbox" className="rounded border-gray-300" checked={isShared} onChange={(e) => setIsShared(e.target.checked)} />
                        Share with my role
                    </label>
                    <button type="button" className="btn-primary w-full py-1.5" onClick={save}>
                        Save
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Declarative filter toolbar synced to URL query params via Inertia.
 * filters: [{ name, label, type: 'search'|'select'|'checkbox', options?, placeholder? }]
 * resource: optional key enabling saved views for this list (Phase 7.8).
 */
export default function FilterBar({ filters, currentFilters = {}, route: routeName, searchPlaceholder = 'Search…', resource = null }) {
    const [search, setSearch] = useState(currentFilters.search ?? '');
    const { features = [] } = usePage().props;
    const savedViewsEnabled = resource && features.includes('saved-views');

    const apply = (extra = {}) => {
        const params = { ...currentFilters, search: search || undefined, ...extra };
        Object.keys(params).forEach((key) => {
            if (params[key] === undefined || params[key] === '' || params[key] === null) delete params[key];
        });
        router.get(route(routeName), params, { preserveState: true, replace: true });
    };

    const hasActiveFilters = Object.values(currentFilters).some((v) => v !== null && v !== '' && v !== undefined);

    return (
        <div className="filter-bar">
            <div className="filter-bar-inner">
                {filters.map((filter) => {
                    if (filter.type === 'search') {
                        return (
                            <div key={filter.name} className="filter-bar-group flex-1">
                                <label className="filter-bar-label">{filter.label ?? 'Search'}</label>
                                <input
                                    type="text"
                                    className="filter-bar-input"
                                    placeholder={filter.placeholder ?? searchPlaceholder}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && apply()}
                                    onBlur={() => search !== (currentFilters.search ?? '') && apply()}
                                />
                            </div>
                        );
                    }

                    if (filter.type === 'select') {
                        return (
                            <div key={filter.name} className="filter-bar-group">
                                <label className="filter-bar-label">{filter.label}</label>
                                <select
                                    className="filter-bar-select"
                                    value={currentFilters[filter.name] ?? ''}
                                    onChange={(e) => apply({ [filter.name]: e.target.value || undefined })}
                                >
                                    <option value="">All</option>
                                    {(filter.options ?? []).map((option) => {
                                        const value = typeof option === 'object' ? option.value : option;
                                        const label = typeof option === 'object' ? option.label : option;
                                        return (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        );
                                    })}
                                </select>
                            </div>
                        );
                    }

                    if (filter.type === 'checkbox') {
                        return (
                            <label key={filter.name} className="filter-bar-group flex-row items-center gap-2 pb-2">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                    checked={!!currentFilters[filter.name]}
                                    onChange={(e) => apply({ [filter.name]: e.target.checked ? 1 : undefined })}
                                />
                                <span className="text-sm text-gray-600">{filter.label}</span>
                            </label>
                        );
                    }

                    return null;
                })}

                {hasActiveFilters && (
                    <button
                        type="button"
                        className="filter-bar-reset pb-2"
                        onClick={() => {
                            setSearch('');
                            router.get(route(routeName), {}, { preserveState: false });
                        }}
                    >
                        Reset filters
                    </button>
                )}

                {savedViewsEnabled && (
                    <SavedViews resource={resource} routeName={routeName} currentFilters={currentFilters} />
                )}
            </div>
        </div>
    );
}
