import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Gavel, Lock } from 'lucide-react';

const humanise = (value) => (value ? value.replace(/_/g, ' ') : '');

/**
 * CR-04 — opening an investigation.
 *
 * `prefill` arrives when the page was reached from a "raise from" button
 * on a case, an exception, an incident, a complaint or a failed test. Where
 * the origin is a Speak Up report, confidentiality is inherited and the
 * switch is disabled rather than merely defaulted: the service would refuse
 * the change anyway, and a control that silently does nothing is worse than
 * no control at all.
 */
export default function Create({ options = {}, prefill = null }) {
    const locked = Boolean(prefill?.confidentiality_locked);

    const form = useForm({
        title: prefill?.title ?? '',
        description: '',
        category: prefill?.category ?? 'fraud',
        source: prefill?.source ?? 'management_directive',
        priority: 'Medium',
        is_confidential: prefill?.is_confidential ?? false,
        control_entity_id: prefill?.control_entity_id ?? '',
        organisation_unit_id: '',
        lead_investigator_id: '',
        team_member_ids: [],
        reported_date: new Date().toISOString().slice(0, 10),
        target_completion_date: '',
        estimated_financial_impact: prefill?.estimated_financial_impact ?? '',
        currency: prefill?.currency ?? 'NGN',
        origin_type: prefill?.origin_type ?? '',
        origin_id: prefill?.origin_id ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('investigations.store'));
    };

    return (
        <AuthenticatedLayout header="Investigations">
            <Head title="New investigation" />

            <PageHeader
                title="Open an investigation"
                subtitle="Fraud, staff misconduct, asset misappropriation, conflicts of interest — the casework the control function runs itself"
                icon={Gavel}
                breadcrumbs={[{ label: 'Investigations', href: route('investigations.index') }, { label: 'New' }]}
            />

            {prefill?.origin_type && (
                <div className="mb-6 rounded-lg border-l-4 border-l-[var(--color-primary)] bg-blue-50 p-4 text-sm">
                    <p className="font-semibold text-[var(--color-text-primary)]">
                        Raised from a {humanise(prefill.origin_type)}
                    </p>
                    <p className="mt-1 text-[var(--color-text-secondary)]">
                        The originating record stays linked to this investigation — as a hard reference and as an edge in
                        the relationship graph.
                        {locked && ' Because it is a Speak Up report, this investigation is confidential and cannot be opened up.'}
                    </p>
                </div>
            )}

            <form onSubmit={submit} className="card">
                <div className="card-body space-y-5">
                    <div>
                        <InputLabel htmlFor="title" value="Title" />
                        <TextInput
                            id="title"
                            className="mt-1 block w-full"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.title} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="What is alleged" />
                        <TextArea
                            id="description"
                            rows={4}
                            className="mt-1 block w-full"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel htmlFor="category" value="Category" />
                            <SelectInput
                                id="category"
                                className="mt-1 block w-full capitalize"
                                value={form.data.category}
                                onChange={(e) => form.setData('category', e.target.value)}
                            >
                                {(options.categories ?? []).map((value) => (
                                    <option key={value} value={value}>{humanise(value)}</option>
                                ))}
                            </SelectInput>
                            <InputError message={form.errors.category} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="source" value="How it came to us" />
                            <SelectInput
                                id="source"
                                className="mt-1 block w-full capitalize"
                                value={form.data.source}
                                onChange={(e) => form.setData('source', e.target.value)}
                            >
                                {(options.sources ?? []).map((value) => (
                                    <option key={value} value={value}>{humanise(value)}</option>
                                ))}
                            </SelectInput>
                            <InputError message={form.errors.source} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="priority" value="Priority" />
                            <SelectInput
                                id="priority"
                                className="mt-1 block w-full"
                                value={form.data.priority}
                                onChange={(e) => form.setData('priority', e.target.value)}
                            >
                                {(options.priorities ?? []).map((value) => (
                                    <option key={value} value={value}>{value}</option>
                                ))}
                            </SelectInput>
                            <InputError message={form.errors.priority} className="mt-1" />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="control_entity_id" value="Investigating desk or branch" />
                            <SelectInput
                                id="control_entity_id"
                                className="mt-1 block w-full"
                                value={form.data.control_entity_id ?? ''}
                                onChange={(e) => form.setData('control_entity_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {(options.controlEntities ?? []).map((entity) => (
                                    <option key={entity.id} value={entity.id}>{entity.name}</option>
                                ))}
                            </SelectInput>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                The control unit that owns the matter — not the department under investigation.
                            </p>
                        </div>

                        <div>
                            <InputLabel htmlFor="organisation_unit_id" value="Department under investigation" />
                            <SelectInput
                                id="organisation_unit_id"
                                className="mt-1 block w-full"
                                value={form.data.organisation_unit_id ?? ''}
                                onChange={(e) => form.setData('organisation_unit_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {(options.organisationUnits ?? []).map((unit) => (
                                    <option key={unit.id} value={unit.id}>{unit.name}</option>
                                ))}
                            </SelectInput>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel htmlFor="reported_date" value="Reported" />
                            <TextInput
                                id="reported_date"
                                type="date"
                                className="mt-1 block w-full"
                                value={form.data.reported_date}
                                onChange={(e) => form.setData('reported_date', e.target.value)}
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="target_completion_date" value="Target completion" />
                            <TextInput
                                id="target_completion_date"
                                type="date"
                                className="mt-1 block w-full"
                                value={form.data.target_completion_date}
                                onChange={(e) => form.setData('target_completion_date', e.target.value)}
                            />
                            <InputError message={form.errors.target_completion_date} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="estimated_financial_impact" value="Estimated impact" />
                            <TextInput
                                id="estimated_financial_impact"
                                type="number"
                                step="0.01"
                                min="0"
                                className="mt-1 block w-full"
                                value={form.data.estimated_financial_impact ?? ''}
                                onChange={(e) => form.setData('estimated_financial_impact', e.target.value)}
                            />
                        </div>
                    </div>

                    <label className="flex items-start gap-3 rounded-lg border border-gray-200 p-3">
                        <input
                            type="checkbox"
                            className="mt-0.5"
                            checked={Boolean(form.data.is_confidential)}
                            disabled={locked}
                            onChange={(e) => form.setData('is_confidential', e.target.checked)}
                        />
                        <span className="text-sm">
                            <span className="flex items-center gap-1.5 font-medium text-[var(--color-text-primary)]">
                                {locked && <Lock className="h-3.5 w-3.5" aria-hidden="true" />}
                                Confidential
                            </span>
                            <span className="mt-0.5 block text-[var(--color-text-secondary)]">
                                {locked
                                    ? 'Inherited from the Speak Up report this was raised from, and locked. The protection belongs to a reporter who is not on this team.'
                                    : 'A confidential investigation opens only to its lead, its team and the named confidential authority. Every read of it is logged on the case timeline.'}
                            </span>
                        </span>
                    </label>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
                    <Link href={route('investigations.index')}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={form.processing}>Open investigation</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
