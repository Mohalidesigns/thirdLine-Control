import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Widget from '@/Components/Charts/Widget';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const WIDTHS = [
    { value: 3, label: 'Quarter' },
    { value: 4, label: 'Third' },
    { value: 6, label: 'Half' },
    { value: 8, label: 'Two thirds' },
    { value: 12, label: 'Full' },
];

const HEIGHTS = [2, 3, 4, 5, 6];

/**
 * The dashboard builder.
 *
 * The canvas is a flow over a 12-column grid: tiles sit in order, each with a
 * width and a height, and x/y are derived from that order rather than typed
 * in. Two reasons — it reflows correctly at 375px, which a free-form pixel
 * grid does not, and dragging to reorder works with a finger as well as a
 * mouse.
 *
 * A tile can only ever name a dataset from the palette on the left, which is
 * itself filtered to what this composer may read.
 */
export default function Builder({
    dashboard,
    widgets: initialWidgets = [],
    sources = [],
    widgetTypes = [],
    visibilities = [],
    roles = [],
    maxWidgets = 24,
    canPublish = false,
}) {
    const [widgets, setWidgets] = useState(() => initialWidgets.map(normalise));
    const [dragging, setDragging] = useState(null);
    const [search, setSearch] = useState('');
    const [dirty, setDirty] = useState(false);

    const form = useForm({
        name: dashboard?.name ?? '',
        description: dashboard?.description ?? '',
        visibility: dashboard?.visibility ?? 'private',
        role_ids: dashboard?.role_ids ?? [],
        refresh_interval_seconds: dashboard?.refresh_interval_seconds ?? 0,
    });

    const grouped = useMemo(() => {
        const term = search.trim().toLowerCase();

        return sources
            .filter((source) => !term
                || source.label.toLowerCase().includes(term)
                || source.group.toLowerCase().includes(term))
            .reduce((groups, source) => {
                (groups[source.group] ??= []).push(source);

                return groups;
            }, {});
    }, [sources, search]);

    const addWidget = (source) => {
        if (widgets.length >= maxWidgets) {
            return;
        }

        setWidgets((current) => [
            ...current,
            {
                id: null,
                key: `new-${current.length}-${source.key}`,
                widget_type: source.defaults.widget_type,
                title: source.defaults.title ?? source.label,
                subtitle: null,
                data_source_key: source.key,
                query_config: {},
                display_config: {},
                position: { x: 0, y: 0, w: source.defaults.w ?? 4, h: source.defaults.h ?? 3 },
                permission_required: null,
                is_visible: true,
                preview: null,
                types: source.types,
            },
        ]);
        setDirty(true);
    };

    const patch = (index, changes) => {
        setWidgets((current) => current.map((widget, i) => (i === index ? { ...widget, ...changes } : widget)));
        setDirty(true);
    };

    const remove = (index) => {
        setWidgets((current) => current.filter((_, i) => i !== index));
        setDirty(true);
    };

    const move = (from, to) => {
        if (from === to || to < 0 || to >= widgets.length) {
            return;
        }

        setWidgets((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);
            next.splice(to, 0, moved);

            return next;
        });
        setDirty(true);
    };

    /** x/y fall out of the order and the widths — never typed by hand. */
    const withGeometry = () => {
        let x = 0;
        let y = 0;

        return widgets.map((widget) => {
            const w = widget.position.w;

            if (x + w > 12) {
                x = 0;
                y += 1;
            }

            const placed = { ...widget, position: { ...widget.position, x, y } };
            x += w;

            return placed;
        });
    };

    const save = () => {
        const payload = {
            ...form.data,
            widgets: withGeometry().map((widget) => ({
                widget_type: widget.widget_type,
                title: widget.title,
                subtitle: widget.subtitle,
                data_source_key: widget.data_source_key,
                query_config: widget.query_config ?? {},
                display_config: widget.display_config ?? {},
                position: widget.position,
                permission_required: widget.permission_required,
                is_visible: widget.is_visible,
            })),
        };

        const done = { onSuccess: () => setDirty(false), preserveScroll: true };

        if (dashboard?.id) {
            router.put(route('dashboards.update', dashboard.slug), payload, done);
        } else {
            router.post(route('dashboards.store'), payload, done);
        }
    };

    return (
        <AuthenticatedLayout header={dashboard ? `Edit ${dashboard.name}` : 'New dashboard'}>
            <Head title={dashboard ? `Edit ${dashboard.name}` : 'New dashboard'} />

            <PageHeader
                title={dashboard ? `Edit ${dashboard.name}` : 'New dashboard'}
                subtitle={`${widgets.length} of ${maxWidgets} widgets. Drag a tile by its handle to reorder.`}
                breadcrumbs={[{ label: 'Dashboards', href: route('dashboards.index') }, { label: 'Builder' }]}
                actions={
                    <>
                        <Link href={route('dashboards.index')} className="btn-secondary">
                            Cancel
                        </Link>
                        <button type="button" onClick={save} className="btn-primary" disabled={!form.data.name}>
                            {dirty ? 'Save changes' : 'Saved'}
                        </button>
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
                {/* ── Palette ───────────────────────────────────────────── */}
                <aside className="card lg:col-span-1">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Widget palette</h2>
                    </div>
                    <div className="card-body space-y-3">
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search datasets"
                            className="form-input"
                            aria-label="Search datasets"
                        />

                        <div className="max-h-[28rem] space-y-4 overflow-y-auto pe-1">
                            {Object.entries(grouped).map(([group, items]) => (
                                <div key={group}>
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        {group}
                                    </p>
                                    <ul className="space-y-1">
                                        {items.map((source) => (
                                            <li key={source.key}>
                                                <button
                                                    type="button"
                                                    onClick={() => addWidget(source)}
                                                    disabled={widgets.length >= maxWidgets}
                                                    className="w-full rounded-lg border border-gray-100 px-2.5 py-2 text-left text-sm hover:border-[var(--color-primary)] hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    <span className="block font-medium text-[var(--color-text-primary)]">
                                                        {source.label}
                                                    </span>
                                                    <span className="block text-[10px] text-gray-400">
                                                        {source.types.join(' · ')}
                                                    </span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </div>
                </aside>

                {/* ── Settings and canvas ───────────────────────────────── */}
                <div className="space-y-4 lg:col-span-3">
                    <section className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold">Dashboard settings</h2>
                        </div>
                        <div className="card-body grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label className="form-label" htmlFor="dashboard-name">Name</label>
                                <input
                                    id="dashboard-name"
                                    className="form-input"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </div>
                            <div>
                                <label className="form-label" htmlFor="dashboard-visibility">Visibility</label>
                                <select
                                    id="dashboard-visibility"
                                    className="form-select"
                                    value={form.data.visibility}
                                    onChange={(event) => form.setData('visibility', event.target.value)}
                                >
                                    {visibilities.map((visibility) => (
                                        <option
                                            key={visibility}
                                            value={visibility}
                                            disabled={visibility !== 'private' && !canPublish}
                                        >
                                            {VISIBILITY_LABELS[visibility]}
                                            {visibility !== 'private' && !canPublish ? ' — needs publish permission' : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="md:col-span-2">
                                <label className="form-label" htmlFor="dashboard-description">Description</label>
                                <input
                                    id="dashboard-description"
                                    className="form-input"
                                    value={form.data.description ?? ''}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                />
                            </div>
                            {form.data.visibility === 'role' && (
                                <fieldset className="md:col-span-2">
                                    <legend className="form-label">Roles that may open it</legend>
                                    <div className="flex flex-wrap gap-3">
                                        {roles.map((role) => (
                                            <label key={role.id} className="flex items-center gap-1.5 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.role_ids.includes(role.id)}
                                                    onChange={(event) => form.setData(
                                                        'role_ids',
                                                        event.target.checked
                                                            ? [...form.data.role_ids, role.id]
                                                            : form.data.role_ids.filter((id) => id !== role.id),
                                                    )}
                                                />
                                                {role.name}
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            )}
                        </div>
                    </section>

                    {widgets.length === 0 ? (
                        <div className="card">
                            <div className="card-body text-center text-sm text-[var(--color-text-secondary)]">
                                Pick a dataset from the palette to place your first tile.
                            </div>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                            {widgets.map((widget, index) => (
                                <div
                                    key={widget.key ?? widget.id}
                                    className={`dash-tile ${dragging === index ? 'opacity-60' : ''}`}
                                    style={{ '--tile-span': widget.position.w }}
                                    onDragOver={(event) => event.preventDefault()}
                                    onDrop={() => {
                                        if (dragging !== null) {
                                            move(dragging, index);
                                            setDragging(null);
                                        }
                                    }}
                                >
                                    <div className="card h-full">
                                        <div className="card-header gap-2">
                                            <span
                                                draggable
                                                onDragStart={() => setDragging(index)}
                                                onDragEnd={() => setDragging(null)}
                                                className="cursor-grab select-none text-gray-300"
                                                title="Drag to reorder"
                                                aria-hidden="true"
                                            >
                                                ⠿
                                            </span>
                                            <input
                                                className="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm font-semibold focus:ring-0"
                                                value={widget.title}
                                                onChange={(event) => patch(index, { title: event.target.value })}
                                                aria-label="Widget title"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => move(index, index - 1)}
                                                className="text-xs text-gray-400 hover:text-[var(--color-primary)]"
                                                aria-label="Move earlier"
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => move(index, index + 1)}
                                                className="text-xs text-gray-400 hover:text-[var(--color-primary)]"
                                                aria-label="Move later"
                                            >
                                                ↓
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => remove(index)}
                                                className="text-xs text-[var(--color-error)] hover:underline"
                                            >
                                                Remove
                                            </button>
                                        </div>

                                        <div className="card-body space-y-3">
                                            <div className="grid grid-cols-3 gap-2">
                                                <label className="block">
                                                    <span className="filter-bar-label">Chart</span>
                                                    <select
                                                        className="form-select"
                                                        value={widget.widget_type}
                                                        onChange={(event) => patch(index, { widget_type: event.target.value })}
                                                    >
                                                        {(widget.types ?? widgetTypes).map((type) => (
                                                            <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <label className="block">
                                                    <span className="filter-bar-label">Width</span>
                                                    <select
                                                        className="form-select"
                                                        value={widget.position.w}
                                                        onChange={(event) => patch(index, {
                                                            position: { ...widget.position, w: Number(event.target.value) },
                                                        })}
                                                    >
                                                        {WIDTHS.map((option) => (
                                                            <option key={option.value} value={option.value}>{option.label}</option>
                                                        ))}
                                                    </select>
                                                </label>
                                                <label className="block">
                                                    <span className="filter-bar-label">Height</span>
                                                    <select
                                                        className="form-select"
                                                        value={widget.position.h}
                                                        onChange={(event) => patch(index, {
                                                            position: { ...widget.position, h: Number(event.target.value) },
                                                        })}
                                                    >
                                                        {HEIGHTS.map((height) => (
                                                            <option key={height} value={height}>{height}</option>
                                                        ))}
                                                    </select>
                                                </label>
                                            </div>

                                            <p className="text-[10px] text-gray-400">
                                                Dataset: <span className="font-mono">{widget.data_source_key}</span>
                                            </p>

                                            {widget.preview && (
                                                <div className="rounded-lg border border-dashed border-gray-200 p-2">
                                                    <Widget
                                                        widget={{
                                                            title: widget.title,
                                                            type: widget.widget_type,
                                                            data: widget.preview,
                                                        }}
                                                        onDrill={false}
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

const VISIBILITY_LABELS = {
    private: 'Private to you',
    role: 'Shared with selected roles',
    tenant: 'Everyone in the organisation',
    public_link: 'Anyone with the link',
};

function normalise(widget) {
    return {
        ...widget,
        key: `saved-${widget.id}`,
        position: widget.position ?? { x: 0, y: 0, w: 4, h: 3 },
        query_config: widget.query_config ?? {},
        display_config: widget.display_config ?? {},
    };
}
