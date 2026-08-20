import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import StatCard from '@/Components/StatCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Banknote, CircleSlash, Gauge } from 'lucide-react';

/**
 * Concentration by vendor, by service and by jurisdiction. Spend that
 * could not be converted is shown, not hidden — a concentration figure
 * that silently omitted a subsidiary would be worse than none.
 */
export default function Concentration({ concentration = {}, filters = {}, entities = [], currencies = [] }) {
    const { base_currency: base, total, threshold, by_vendor = [], by_category = [], by_jurisdiction = [] } = concentration;
    const breaches = concentration.breaches ?? [];
    const unconverted = concentration.unconverted ?? [];

    return (
        <AuthenticatedLayout header="Concentration Risk">
            <Head title="Concentration Risk" />

            <PageHeader
                title="Third-party concentration"
                subtitle={`Exposure by vendor, service and jurisdiction, converted to ${base} at the reference rate`}
                breadcrumbs={[{ label: 'Third parties', href: route('vendors.index') }, { label: 'Concentration' }]}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard icon={Banknote} title={`Total spend (${base})`} value={total?.formatted ?? '—'} color="primary" />
                <StatCard icon={Gauge} title="Third parties counted" value={concentration.vendor_count ?? 0} color="info" />
                <StatCard icon={Gauge}
                    title={`Above ${threshold}% of spend`}
                    value={breaches.length}
                    color="danger"
                    prominent={breaches.length > 0}
                />
                <StatCard icon={CircleSlash} tone="slate"
                    title="Unconverted"
                    value={unconverted.length}
                    subtitle={unconverted.length > 0 ? 'No reference rate — excluded from totals' : 'All spend converted'}
                    color={unconverted.length > 0 ? 'warning' : 'success'}
                />
            </div>

            <FilterBar
                route="vendors.concentration"
                currentFilters={filters}
                filters={[
                    {
                        name: 'base_currency',
                        type: 'select',
                        label: 'Base currency',
                        options: currencies.map((c) => ({ value: c, label: c })),
                    },
                    {
                        name: 'entity_id',
                        type: 'select',
                        label: 'Entity',
                        options: entities.map((e) => ({ value: e.id, label: e.legal_name })),
                    },
                ]}
            />

            {unconverted.length > 0 && (
                <div className="mb-6 rounded-lg border border-[var(--color-warning)] bg-orange-50 p-4">
                    <p className="text-sm font-semibold text-[var(--color-warning)]">
                        {unconverted.length} third part{unconverted.length === 1 ? 'y is' : 'ies are'} excluded from these
                        totals
                    </p>
                    <ul className="mt-2 space-y-1 text-xs text-[var(--color-text-secondary)]">
                        {unconverted.map((row, index) => (
                            <li key={index}>
                                {row.vendor} — {row.amount} {row.currency}: no {row.currency}/{base} reference rate is loaded.
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <ShareCard title="By service category" rows={by_category} base={base} threshold={threshold} />
                <ShareCard title="By processing jurisdiction" rows={by_jurisdiction} base={base} threshold={threshold} />
            </div>

            <section className="card mt-6">
                <div className="card-header">
                    <h2 className="text-sm font-semibold text-[var(--color-primary)]">By third party</h2>
                </div>
                <div className="card-body overflow-x-auto">
                    <table className="data-table w-full min-w-[40rem]">
                        <thead>
                            <tr>
                                <th>Third party</th>
                                <th>Category</th>
                                <th>Jurisdiction</th>
                                <th className="text-right">Spend ({base})</th>
                                <th className="w-40">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            {by_vendor.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="py-6 text-center text-xs text-gray-400">
                                        No spend recorded against any third party.
                                    </td>
                                </tr>
                            ) : (
                                by_vendor.map((row) => (
                                    <tr key={row.id}>
                                        <td>
                                            <Link
                                                href={route('vendors.show', row.id)}
                                                className="font-medium text-[var(--color-primary)] hover:underline"
                                            >
                                                {row.name}
                                            </Link>
                                            <p className="text-xs text-gray-400">
                                                {row.reference} · {row.criticality}
                                                {row.entity ? ` · ${row.entity}` : ''}
                                            </p>
                                        </td>
                                        <td className="text-xs">{row.category}</td>
                                        <td className="text-xs">{row.processing_country}</td>
                                        <td className="text-right tabular-nums">{row.spend}</td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                <span className="flex-1">
                                                    <ProgressBar
                                                        value={row.share}
                                                        color={
                                                            row.share >= threshold
                                                                ? 'var(--color-error)'
                                                                : 'var(--color-primary)'
                                                        }
                                                    />
                                                </span>
                                                <span
                                                    className={`w-12 text-right text-xs tabular-nums ${
                                                        row.share >= threshold ? 'font-semibold text-[var(--color-error)]' : ''
                                                    }`}
                                                >
                                                    {row.share}%
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function ShareCard({ title, rows, base, threshold }) {
    return (
        <section className="card">
            <div className="card-header">
                <h2 className="text-sm font-semibold text-[var(--color-primary)]">{title}</h2>
            </div>
            <div className="card-body space-y-3">
                {rows.length === 0 ? (
                    <p className="text-xs text-gray-400">Nothing to group.</p>
                ) : (
                    rows.map((row) => (
                        <div key={row.label}>
                            <div className="mb-1 flex items-center justify-between text-xs">
                                <span className="font-medium text-[var(--color-text-primary)]">
                                    {row.label}
                                    <span className="ml-2 text-gray-400">
                                        {row.vendors} vendor{row.vendors === 1 ? '' : 's'}
                                        {row.critical > 0 ? ` · ${row.critical} critical` : ''}
                                    </span>
                                </span>
                                <span
                                    className={`tabular-nums ${row.share >= threshold ? 'font-semibold text-[var(--color-error)]' : ''}`}
                                >
                                    {row.share}% · {row.spend} {base}
                                </span>
                            </div>
                            <ProgressBar
                                value={row.share}
                                color={row.share >= threshold ? 'var(--color-error)' : 'var(--color-primary)'}
                            />
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}
