import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import SelectInput from '@/Components/SelectInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDateTime } from '@/utils';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function PlanReport({ plan }) {
    if (!plan) return null;

    return (
        <div className="mb-6 card border-l-4 border-l-[var(--color-info)]">
            <div className="card-header">
                <h2 className="text-sm font-semibold">
                    Dry run — {plan.code} {plan.version}
                </h2>
            </div>
            <div className="card-body space-y-3 text-sm">
                <ul className="space-y-1">
                    <li>
                        Framework <span className="font-mono text-xs">{plan.framework?.code}</span> will be{' '}
                        <strong>{plan.framework?.action}d</strong>.
                    </li>
                    <li>
                        Requirements: <strong>{plan.requirements?.create}</strong> new,{' '}
                        <strong>{plan.requirements?.update}</strong> updated, {plan.requirements?.orphaned} present in the
                        database but absent from the pack (left untouched — a pack never deletes).
                    </li>
                    <li>
                        Obligations: <strong>{plan.obligations?.create}</strong> new,{' '}
                        <strong>{plan.obligations?.update}</strong> updated.
                    </li>
                    <li>
                        {plan.controls} suggested control template(s), {plan.mappings} cross-framework mapping(s).
                    </li>
                </ul>

                <p className="text-xs text-gray-500">
                    Verification — pack: {plan.verification_summary?.pack}; requirements verified/unverified/draft:{' '}
                    {plan.verification_summary?.requirements?.verified}/{plan.verification_summary?.requirements?.unverified}/
                    {plan.verification_summary?.requirements?.draft}; obligations:{' '}
                    {plan.verification_summary?.obligations?.verified}/{plan.verification_summary?.obligations?.unverified}/
                    {plan.verification_summary?.obligations?.draft}
                </p>

                {(plan.tenant_overrides?.obligations?.length > 0 || plan.tenant_overrides?.frameworks?.length > 0) && (
                    <div className="rounded-lg bg-amber-50 p-3 text-xs">
                        <p className="font-semibold">Tenant customisations preserved</p>
                        <p className="mt-1">
                            These records exist as tenant-owned copies and will not be modified by the install:{' '}
                            {[...(plan.tenant_overrides.frameworks ?? []), ...(plan.tenant_overrides.obligations ?? [])].join(', ')}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function ContentPacks({ packs = [], installed = [], can = {} }) {
    const { packPlan } = usePage().props;
    const [versions, setVersions] = useState({});

    const act = (code, action) =>
        router.post(
            action === 'preview' ? route('admin.content-packs.preview') : route('admin.content-packs.install'),
            { code, version: versions[code] ?? null },
            { preserveScroll: true },
        );

    const columns = [
        {
            field: 'code',
            label: 'Pack',
            render: (row) => (
                <div>
                    <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.code}</span>
                    {row.name && <p className="text-sm">{row.name}</p>}
                </div>
            ),
        },
        {
            field: 'version',
            label: 'Version',
            render: (row) => (
                <SelectInput
                    value={versions[row.code] ?? row.latest}
                    onChange={(e) => setVersions((current) => ({ ...current, [row.code]: e.target.value }))}
                    className="w-28 text-xs"
                    aria-label={`Version for ${row.code}`}
                >
                    {row.versions.map((version) => (
                        <option key={version} value={version}>
                            {version}
                        </option>
                    ))}
                </SelectInput>
            ),
        },
        {
            field: 'installed_version',
            label: 'Installed',
            render: (row) =>
                row.installed_version ? (
                    <span className="badge badge-status-active">{row.installed_version}</span>
                ) : (
                    <span className="badge badge-status-draft">not installed</span>
                ),
        },
        {
            field: 'verification',
            label: 'Verification',
            render: (row) =>
                row.verification_summary ? (
                    <span className="text-xs text-gray-500">
                        {row.verification_summary.pack} · {row.verification_summary.obligations?.verified ?? 0} verified
                        obligation(s)
                    </span>
                ) : (
                    '—'
                ),
        },
        {
            field: 'actions',
            label: '',
            render: (row) =>
                can.install && (
                    <div className="flex gap-2">
                        <button type="button" onClick={() => act(row.code, 'preview')} className="btn-secondary text-xs">
                            Dry run
                        </button>
                        <button type="button" onClick={() => act(row.code, 'install')} className="btn-primary text-xs">
                            Install
                        </button>
                    </div>
                ),
        },
    ];

    const installedColumns = [
        { field: 'code', label: 'Pack', render: (row) => <span className="font-mono text-xs">{row.code}</span> },
        { field: 'name', label: 'Name' },
        { field: 'version', label: 'Version' },
        { field: 'jurisdiction', label: 'Jurisdiction' },
        { field: 'installed_at', label: 'Installed', render: (row) => formatDateTime(row.installed_at) },
        { field: 'installer', label: 'By', render: (row) => row.installer?.name ?? 'CLI' },
        {
            field: 'checksum',
            label: 'Checksum',
            render: (row) => <span className="font-mono text-xs text-gray-400">{row.checksum?.slice(0, 12)}…</span>,
        },
    ];

    return (
        <AuthenticatedLayout header="Content Packs">
            <Head title="Content Packs" />

            <PageHeader
                title="Regulatory Content Packs"
                subtitle="Versioned, checksummed regulatory content. Installs are idempotent and never overwrite a tenant’s own records."
            />

            <PlanReport plan={packPlan} />

            <div className="mb-4 rounded-lg border-l-4 border-l-[var(--color-warning)] bg-amber-50 px-4 py-3 text-sm">
                A pack marked anything other than <strong>verified</strong> installs normally but its records are excluded
                from generated regulatory submissions until a person confirms each date, threshold and penalty against the
                regulator’s primary document.
            </div>

            <div className="card mb-6">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Available packs</h2>
                </div>
                <DataTable
                    columns={columns}
                    data={packs}
                    emptyMessage="No content packs found in database/content-packs."
                />
            </div>

            <div className="card">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Installation history</h2>
                </div>
                <DataTable columns={installedColumns} data={installed} emptyMessage="Nothing installed yet." />
            </div>
        </AuthenticatedLayout>
    );
}
