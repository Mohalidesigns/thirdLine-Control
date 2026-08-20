import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Building2, Landmark, MonitorCog, Network } from 'lucide-react';
import { useState } from 'react';

const DOMAIN_ICONS = {
    head_office: Landmark,
    information_systems: MonitorCog,
    branch: Building2,
    other: Network,
};

const DOMAIN_BLURBS = {
    head_office: 'Head office departments under second-line oversight.',
    information_systems: 'Information systems control domains.',
    branch: 'The branch network, derived from the organisation tree.',
    other: 'Tenant-defined control sub-unit.',
};

const EMPTY = { code: '', name: '', domain: 'other', description: '', sequence: 10, is_active: true };

export default function Index({ units = [], counts = {}, domains = [], can = {} }) {
    const [editing, setEditing] = useState(null); // null | 'new' | unit
    const form = useForm(EMPTY);

    const open = (unit) => {
        if (unit === 'new') {
            form.setDefaults(EMPTY);
            form.reset();
        } else {
            form.setData({
                ...EMPTY,
                ...Object.fromEntries(Object.keys(EMPTY).map((k) => [k, unit[k] ?? EMPTY[k]])),
            });
        }
        setEditing(unit);
    };

    const submit = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post(route('control-structure.units.store'), options);
        } else {
            form.put(route('control-structure.units.update', editing.id), options);
        }
    };

    return (
        <AuthenticatedLayout header="Control Structure">
            <Head title="Control Structure" />

            <PageHeader
                title="Internal Control structure"
                subtitle="The sub-units of the control function and the control universe each one oversees"
                actions={can.manage && (
                    <PrimaryButton onClick={() => open('new')}>New sub-unit</PrimaryButton>
                )}
            />

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {units.map((unit) => {
                    const Icon = DOMAIN_ICONS[unit.domain] ?? Network;
                    const c = counts[unit.id] ?? {};

                    return (
                        <div key={unit.id} className="card transition-all duration-200 hover:shadow-md">
                            <div className="card-body">
                                <div className="flex items-start justify-between">
                                    <Link href={route('control-structure.unit', unit.id)} className="group flex items-start gap-3">
                                        <span className="rounded-lg bg-[var(--color-primary)]/5 p-2.5 text-[var(--color-primary)]">
                                            <Icon className="h-6 w-6" strokeWidth={1.6} />
                                        </span>
                                        <span>
                                            <span className="block font-semibold text-[var(--color-text-primary)] group-hover:text-[var(--color-primary)]">
                                                {unit.name}
                                            </span>
                                            <span className="font-mono text-xs text-gray-400">{unit.code}</span>
                                        </span>
                                    </Link>
                                    {can.manage && (
                                        <button
                                            type="button"
                                            className="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                            onClick={() => open(unit)}
                                        >
                                            Edit
                                        </button>
                                    )}
                                </div>

                                <p className="mt-3 text-sm text-gray-500">
                                    {unit.description || DOMAIN_BLURBS[unit.domain] || ''}
                                </p>

                                <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 pt-4 text-sm">
                                    <Count label="Entities" value={c.entities} />
                                    <Count label="Attached controls" value={c.controls} />
                                    <Count label="Open exceptions" value={c.open_exceptions} tone={c.open_exceptions > 0 ? 'text-red-600' : ''} />
                                    <Count label="Reviews overdue" value={c.overdue_reviews} tone={c.overdue_reviews > 0 ? 'text-orange-600' : ''} />
                                </dl>

                                {unit.head && (
                                    <p className="mt-3 text-xs text-gray-400">Head: {unit.head.name}</p>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            <p className="mt-4 text-xs text-gray-500">
                Branch Control derives its branch list from the organisation tree — a branch created there appears here automatically.
            </p>

            <Modal show={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? 'New control sub-unit' : 'Edit control sub-unit'}>
                <form onSubmit={submit} className="space-y-4 p-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Code" required />
                            <TextInput value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="w-full" />
                            <InputError message={form.errors.code} />
                        </div>
                        <div>
                            <InputLabel value="Name" required />
                            <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="w-full" />
                            <InputError message={form.errors.name} />
                        </div>
                        <div>
                            <InputLabel value="Domain" required />
                            <SelectInput value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)}>
                                {domains.map((d) => <option key={d} value={d}>{d.replaceAll('_', ' ')}</option>)}
                            </SelectInput>
                            <p className="mt-1 text-xs text-gray-400">Behaviour follows the domain, never the name.</p>
                            <InputError message={form.errors.domain} />
                        </div>
                        <div>
                            <InputLabel value="Sequence" />
                            <TextInput type="number" min="0" value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} className="w-full" />
                        </div>
                        <div className="sm:col-span-2">
                            <InputLabel value="Description" />
                            <TextInput value={form.data.description ?? ''} onChange={(e) => form.setData('description', e.target.value)} className="w-full" />
                        </div>
                    </div>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setEditing(null)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>{editing === 'new' ? 'Create sub-unit' : 'Save changes'}</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}

function Count({ label, value, tone = '' }) {
    return (
        <div>
            <dt className="text-xs text-gray-400">{label}</dt>
            <dd className={`text-lg font-semibold ${tone || 'text-[var(--color-text-primary)]'}`}>{value ?? 0}</dd>
        </div>
    );
}
