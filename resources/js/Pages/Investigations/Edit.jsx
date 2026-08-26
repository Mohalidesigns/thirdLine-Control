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

const humanise = (value) => (value ? String(value).replace(/_/g, ' ') : '');

const NARRATIVE = [
    ['background', 'Background', 'How the matter arose and what preceded it.'],
    ['scope', 'Scope', 'What the investigation covered — and what it deliberately did not.'],
    ['objectives', 'Objectives', 'What the investigation set out to establish.'],
    ['methodology', 'Methodology', 'How it was carried out: interviews, extracts, walkthroughs.'],
    ['conclusion', 'Conclusion', 'What the investigation concluded.'],
];

/**
 * CR-04 — editing an investigation, including the four narrative sections
 * the report takes verbatim. The other nine sections are generated from
 * the record, which is what keeps the report and the case in step.
 */
export default function Edit({ investigation = {}, options = {}, can = {} }) {
    const locked = Boolean(investigation.confidentiality_locked) || !can.change_confidentiality;

    const form = useForm({
        title: investigation.title ?? '',
        description: investigation.description ?? '',
        category: investigation.category ?? 'fraud',
        source: investigation.source ?? 'management_directive',
        priority: investigation.priority ?? 'Medium',
        is_confidential: Boolean(investigation.is_confidential),
        control_entity_id: investigation.control_entity_id ?? '',
        organisation_unit_id: investigation.organisation_unit_id ?? '',
        target_completion_date: investigation.target_completion_date?.slice(0, 10) ?? '',
        estimated_financial_impact: investigation.estimated_financial_impact ?? '',
        confirmed_financial_loss: investigation.confirmed_financial_loss ?? '',
        currency: investigation.currency ?? 'NGN',
        background: investigation.background ?? '',
        scope: investigation.scope ?? '',
        objectives: investigation.objectives ?? '',
        methodology: investigation.methodology ?? '',
        conclusion: investigation.conclusion ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('investigations.update', investigation.id));
    };

    return (
        <AuthenticatedLayout header={investigation.reference}>
            <Head title={`Edit ${investigation.reference ?? 'investigation'}`} />

            <PageHeader
                title={`Edit ${investigation.reference}`}
                subtitle={investigation.title}
                icon={Gavel}
                breadcrumbs={[
                    { label: 'Investigations', href: route('investigations.index') },
                    { label: investigation.reference, href: route('investigations.show', investigation.id) },
                    { label: 'Edit' },
                ]}
            />

            <form onSubmit={submit} className="space-y-5">
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">The matter</h3></div>
                    <div className="card-body space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Title" />
                            <TextInput id="title" className="mt-1 block w-full" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required />
                            <InputError message={form.errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="What is alleged" />
                            <TextArea id="description" rows={4} className="mt-1 block w-full" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <InputLabel value="Category" />
                                <SelectInput className="mt-1 block w-full capitalize" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                    {(options.categories ?? []).map((value) => <option key={value} value={value}>{humanise(value)}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Source" />
                                <SelectInput className="mt-1 block w-full capitalize" value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                                    {(options.sources ?? []).map((value) => <option key={value} value={value}>{humanise(value)}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Priority" />
                                <SelectInput className="mt-1 block w-full" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                                    {(options.priorities ?? []).map((value) => <option key={value} value={value}>{value}</option>)}
                                </SelectInput>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Investigating desk or branch" />
                                <SelectInput className="mt-1 block w-full" value={form.data.control_entity_id ?? ''} onChange={(e) => form.setData('control_entity_id', e.target.value)}>
                                    <option value="">—</option>
                                    {(options.controlEntities ?? []).map((entity) => <option key={entity.id} value={entity.id}>{entity.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Department under investigation" />
                                <SelectInput className="mt-1 block w-full" value={form.data.organisation_unit_id ?? ''} onChange={(e) => form.setData('organisation_unit_id', e.target.value)}>
                                    <option value="">—</option>
                                    {(options.organisationUnits ?? []).map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                                </SelectInput>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <InputLabel value="Target completion" />
                                <TextInput type="date" className="mt-1 block w-full" value={form.data.target_completion_date} onChange={(e) => form.setData('target_completion_date', e.target.value)} />
                            </div>
                            <div>
                                <InputLabel value="Estimated impact" />
                                <TextInput type="number" step="0.01" min="0" className="mt-1 block w-full" value={form.data.estimated_financial_impact ?? ''} onChange={(e) => form.setData('estimated_financial_impact', e.target.value)} />
                            </div>
                            <div>
                                <InputLabel value="Confirmed loss" />
                                <TextInput type="number" step="0.01" min="0" className="mt-1 block w-full" value={form.data.confirmed_financial_loss ?? ''} onChange={(e) => form.setData('confirmed_financial_loss', e.target.value)} />
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
                                    {investigation.confidentiality_locked
                                        ? 'Inherited from the Speak Up report this was raised from. It cannot be lowered by anyone on this team.'
                                        : 'Opens only to the lead, the team and the named confidential authority. Every read is logged.'}
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Report narrative</h3></div>
                    <div className="card-body space-y-5">
                        <p className="text-sm text-[var(--color-text-secondary)]">
                            These four sections and the conclusion are the only parts of the report you write. The other
                            nine — parties, chronology, findings, financials, root cause, consequences, recommendations
                            and the evidence register — are generated from the record itself.
                        </p>
                        {NARRATIVE.map(([field, label, hint]) => (
                            <div key={field}>
                                <InputLabel htmlFor={field} value={label} />
                                <TextArea id={field} rows={4} className="mt-1 block w-full" value={form.data[field] ?? ''} onChange={(e) => form.setData(field, e.target.value)} />
                                <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{hint}</p>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <Link href={route('investigations.show', investigation.id)}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={form.processing}>Save</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
