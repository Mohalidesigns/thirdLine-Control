import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Show({ risk, mappableControls = [], users = [] }) {
    const mapForm = useForm({ control_id: '', contribution_weight: 1 });

    const attach = (e) => {
        e.preventDefault();
        mapForm.post(route('risks.controls.attach', risk.id), {
            preserveScroll: true,
            onSuccess: () => mapForm.reset(),
        });
    };

    return (
        <AuthenticatedLayout header={risk.code}>
            <Head title={risk.code} />

            <PageHeader
                title={risk.title}
                subtitle={<span className="font-mono">{risk.code}</span>}
                breadcrumbs={[{ label: 'Risk Register', href: route('risks.index') }, { label: risk.code }]}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Mitigating controls</h3></div>
                        <div className="card-body">
                            {risk.controls?.length ? (
                                <ul className="divide-y divide-gray-100">
                                    {risk.controls.map((control) => (
                                        <li key={control.id} className="flex items-center justify-between gap-3 py-2.5">
                                            <div>
                                                <Link href={route('controls.show', control.id)} className="text-sm font-medium text-[var(--color-primary)] hover:underline">
                                                    <span className="font-mono text-xs">{control.control_ref}</span> — {control.title}
                                                </Link>
                                                <p className="text-xs text-gray-400">
                                                    Owner: {control.owner?.name ?? '—'} · Weight {control.pivot?.contribution_weight ?? 1}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <StatusBadge status={control.status} />
                                                <button
                                                    type="button"
                                                    className="text-xs text-[var(--color-error)] hover:underline"
                                                    onClick={() => router.delete(route('risks.controls.detach', [risk.id, control.id]), { preserveScroll: true })}
                                                >
                                                    Unmap
                                                </button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-[var(--color-error)]">
                                    Control gap — this risk has no mapped control.
                                </p>
                            )}

                            {mappableControls.length > 0 && (
                                <form onSubmit={attach} className="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
                                    <div className="min-w-[260px] flex-1">
                                        <InputLabel value="Map a control" />
                                        <SelectInput value={mapForm.data.control_id} onChange={(e) => mapForm.setData('control_id', e.target.value)}>
                                            <option value="">— Select control —</option>
                                            {mappableControls.map((control) => (
                                                <option key={control.id} value={control.id}>
                                                    {control.control_ref} — {control.title}
                                                </option>
                                            ))}
                                        </SelectInput>
                                    </div>
                                    <div className="w-32">
                                        <InputLabel value="Weight" />
                                        <SelectInput value={mapForm.data.contribution_weight} onChange={(e) => mapForm.setData('contribution_weight', Number(e.target.value))}>
                                            {[0.5, 1, 2, 3].map((w) => <option key={w} value={w}>{w}</option>)}
                                        </SelectInput>
                                    </div>
                                    <PrimaryButton disabled={mapForm.processing || !mapForm.data.control_id}>Map</PrimaryButton>
                                </form>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Description</h3></div>
                        <div className="card-body text-sm text-[var(--color-text-primary)]">
                            {risk.description || '—'}
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="stat-card">
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Inherent risk</p>
                        <p className="mt-2 text-3xl font-bold text-[var(--color-error)]">{risk.inherent_rating}</p>
                        <p className="mt-1 text-xs text-gray-400">
                            Likelihood {risk.inherent_likelihood} × Impact {risk.inherent_impact}
                        </p>
                    </div>
                    <div className="stat-card">
                        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">Residual risk</p>
                        <p className="mt-2 text-3xl font-bold text-[var(--color-primary)]">{risk.residual_rating ?? '—'}</p>
                        <p className="mt-1 text-xs text-gray-400">
                            Derived from mapped control effectiveness and approved compensating controls
                        </p>
                    </div>
                    <div className="card">
                        <div className="card-body space-y-2 text-sm">
                            <p><span className="text-gray-500">Owner:</span> {risk.owner?.name ?? '—'}</p>
                            <p><span className="text-gray-500">Category:</span> {risk.category ?? '—'}</p>
                            <p><span className="text-gray-500">Source:</span> {risk.source}</p>
                            <p><span className="text-gray-500">Status:</span> {risk.status}</p>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
