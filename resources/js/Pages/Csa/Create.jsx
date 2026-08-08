import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Create({ defaultType = 'csa', entities = [], controls = [], users = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        campaign_type: defaultType,
        period_label: '',
        opens_at: '',
        closes_at: '',
        is_anonymous: false,
        variance_threshold: 1,
        response_rate_target: 90,
        scoring_method: 'weighted',
        pass_threshold: 60,
        instructions: '',
        scope_definition: { assignments: [], invited_count: null },
    });

    const isSurvey = data.campaign_type === 'survey';

    const addAssignment = () =>
        setData('scope_definition', {
            ...data.scope_definition,
            assignments: [...(data.scope_definition.assignments ?? []), { respondent_id: '', entity_id: null, control_id: null }],
        });

    const setAssignment = (index, key, value) =>
        setData('scope_definition', {
            ...data.scope_definition,
            assignments: data.scope_definition.assignments.map((a, i) => (i === index ? { ...a, [key]: value || null } : a)),
        });

    const submit = (e) => {
        e.preventDefault();
        post(route('csa-campaigns.store'));
    };

    return (
        <AuthenticatedLayout header="New Campaign">
            <Head title="New Campaign" />
            <PageHeader
                title="New Campaign"
                subtitle="Campaigns are drafts until a second reviewer approves them"
                breadcrumbs={[{ label: 'Campaigns', href: route('csa-campaigns.index') }, { label: 'Create' }]}
            />

            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6">
                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Campaign details</h3></div>
                    <div className="card-body space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="name" value="Name" required />
                                <TextInput id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Q3 2026 Branch CSA" />
                                <InputError message={errors.name} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="campaign_type" value="Type" required />
                                <SelectInput id="campaign_type" value={data.campaign_type} onChange={(e) => setData('campaign_type', e.target.value)}>
                                    <option value="csa">Control self-assessment</option>
                                    <option value="attestation">Attestation</option>
                                    <option value="survey">Survey</option>
                                </SelectInput>
                                <InputError message={errors.campaign_type} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <TextArea id="description" rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <InputLabel htmlFor="period_label" value="Period" />
                                <TextInput id="period_label" value={data.period_label} onChange={(e) => setData('period_label', e.target.value)} placeholder="Q3 2026" />
                            </div>
                            <div>
                                <InputLabel htmlFor="opens_at" value="Opens" />
                                <TextInput id="opens_at" type="date" value={data.opens_at} onChange={(e) => setData('opens_at', e.target.value)} />
                            </div>
                            <div>
                                <InputLabel htmlFor="closes_at" value="Closes" />
                                <TextInput id="closes_at" type="date" value={data.closes_at} onChange={(e) => setData('closes_at', e.target.value)} />
                                <InputError message={errors.closes_at} className="mt-1" />
                            </div>
                        </div>

                        {isSurvey && (
                            <label className="flex items-center gap-2">
                                <Checkbox checked={data.is_anonymous} onChange={(e) => setData('is_anonymous', e.target.checked)} />
                                <span className="text-sm">
                                    Anonymous — responses can never be linked to a person, by anyone
                                </span>
                            </label>
                        )}
                    </div>
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-sm font-semibold">Scoring & review</h3></div>
                    <div className="card-body grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel htmlFor="scoring_method" value="Scoring method" />
                            <SelectInput id="scoring_method" value={data.scoring_method} onChange={(e) => setData('scoring_method', e.target.value)}>
                                <option value="none">No scoring</option>
                                <option value="weighted">Weighted</option>
                                <option value="maturity">Maturity</option>
                            </SelectInput>
                        </div>
                        <div>
                            <InputLabel htmlFor="pass_threshold" value="Pass threshold (%)" />
                            <TextInput id="pass_threshold" type="number" min="0" max="100" value={data.pass_threshold ?? ''} onChange={(e) => setData('pass_threshold', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel htmlFor="variance_threshold" value="Variance threshold" />
                            <TextInput id="variance_threshold" type="number" min="0" max="5" value={data.variance_threshold} onChange={(e) => setData('variance_threshold', e.target.value)} />
                            <p className="mt-1 text-xs text-gray-400">Self vs reviewer rating gap that flags a variance.</p>
                        </div>
                    </div>
                </div>

                {isSurvey && data.is_anonymous ? (
                    <div className="card">
                        <div className="card-header"><h3 className="text-sm font-semibold">Audience</h3></div>
                        <div className="card-body">
                            <InputLabel htmlFor="invited_count" value="Invited head-count (for the response-rate gauge)" />
                            <TextInput
                                id="invited_count"
                                type="number"
                                min="0"
                                value={data.scope_definition.invited_count ?? ''}
                                onChange={(e) => setData('scope_definition', { ...data.scope_definition, invited_count: e.target.value ? Number(e.target.value) : null })}
                            />
                        </div>
                    </div>
                ) : (
                    <div className="card">
                        <div className="card-header flex items-center justify-between">
                            <h3 className="text-sm font-semibold">Assignments</h3>
                            <button type="button" className="text-xs font-semibold text-[var(--color-primary)]" onClick={addAssignment}>
                                + Add respondent
                            </button>
                        </div>
                        <div className="card-body space-y-2">
                            {(data.scope_definition.assignments ?? []).length === 0 && (
                                <p className="text-sm text-[var(--color-text-secondary)]">
                                    Add the respondents (and optionally the entity and control) each response covers. Responses are generated when the campaign opens.
                                </p>
                            )}
                            {(data.scope_definition.assignments ?? []).map((a, i) => (
                                <div key={i} className="grid grid-cols-1 items-center gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                    <SelectInput value={a.respondent_id ?? ''} onChange={(e) => setAssignment(i, 'respondent_id', e.target.value)}>
                                        <option value="">Respondent…</option>
                                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                    </SelectInput>
                                    <SelectInput value={a.entity_id ?? ''} onChange={(e) => setAssignment(i, 'entity_id', e.target.value)}>
                                        <option value="">Entity (optional)…</option>
                                        {entities.map((en) => <option key={en.id} value={en.id}>{en.name}</option>)}
                                    </SelectInput>
                                    <SelectInput value={a.control_id ?? ''} onChange={(e) => setAssignment(i, 'control_id', e.target.value)}>
                                        <option value="">Control (optional)…</option>
                                        {controls.map((c) => <option key={c.id} value={c.id}>{c.control_ref} — {c.title}</option>)}
                                    </SelectInput>
                                    <button
                                        type="button"
                                        className="text-xs text-[var(--color-error)]"
                                        onClick={() => setData('scope_definition', {
                                            ...data.scope_definition,
                                            assignments: data.scope_definition.assignments.filter((_, j) => j !== i),
                                        })}
                                    >
                                        Remove
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="flex justify-end">
                    <PrimaryButton disabled={processing}>Create campaign</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
