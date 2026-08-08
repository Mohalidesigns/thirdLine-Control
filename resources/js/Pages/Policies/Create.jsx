import InputError from '@/Components/InputError';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

const BLANK_SECTION = { heading: '', body: '', is_mandatory: true };

export default function Create({
    types = [],
    reviewFrequencies = [],
    attestationFrequencies = [],
    categories = [],
    owners = [],
    publishedPolicies = [],
}) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        policy_type: 'policy',
        category_id: '',
        owner_id: '',
        effective_from: '',
        review_frequency: 'annual',
        requires_attestation: false,
        attestation_frequency: '',
        mandatory_training: false,
        supersedes_policy_id: '',
        applies_to: { all_staff: true },
        sections: [{ ...BLANK_SECTION }],
    });

    const setSection = (index, field, value) => {
        const next = data.sections.map((section, i) => (i === index ? { ...section, [field]: value } : section));
        setData('sections', next);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('policies.store'));
    };

    return (
        <AuthenticatedLayout header="New policy">
            <Head title="New policy" />

            <PageHeader
                title="Draft a policy"
                subtitle="Structure it in sections — each one can name the controls and requirements it governs"
                breadcrumbs={[{ label: 'Policies', href: route('policies.index') }, { label: 'New' }]}
            />

            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6">
                <div className="card">
                    <div className="card-header">Identity</div>
                    <div className="card-body space-y-4">
                        <div>
                            <label className="form-label">
                                Title <span className="text-red-500">*</span>
                            </label>
                            <input
                                className="form-input"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <label className="form-label">Description</label>
                            <textarea
                                className="form-input"
                                rows={3}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="form-label">Type</label>
                                <select
                                    className="form-select"
                                    value={data.policy_type}
                                    onChange={(e) => setData('policy_type', e.target.value)}
                                >
                                    {types.map((type) => (
                                        <option key={type} value={type}>
                                            {type}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.policy_type} className="mt-1" />
                            </div>

                            <div>
                                <label className="form-label">Category</label>
                                <select
                                    className="form-select"
                                    value={data.category_id}
                                    onChange={(e) => setData('category_id', e.target.value)}
                                >
                                    <option value="">—</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="form-label">
                                    Owner <span className="text-red-500">*</span>
                                </label>
                                <select
                                    className="form-select"
                                    value={data.owner_id}
                                    onChange={(e) => setData('owner_id', e.target.value)}
                                >
                                    <option value="">Select an owner…</option>
                                    {owners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-gray-400">
                                    The owner can never approve or publish their own policy.
                                </p>
                                <InputError message={errors.owner_id} className="mt-1" />
                            </div>

                            <div>
                                <label className="form-label">Effective from</label>
                                <input
                                    type="date"
                                    className="form-input"
                                    value={data.effective_from}
                                    onChange={(e) => setData('effective_from', e.target.value)}
                                />
                            </div>

                            <div>
                                <label className="form-label">Review frequency</label>
                                <select
                                    className="form-select"
                                    value={data.review_frequency}
                                    onChange={(e) => setData('review_frequency', e.target.value)}
                                >
                                    {reviewFrequencies.map((frequency) => (
                                        <option key={frequency} value={frequency}>
                                            {frequency.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="form-label">Supersedes</label>
                                <select
                                    className="form-select"
                                    value={data.supersedes_policy_id}
                                    onChange={(e) => setData('supersedes_policy_id', e.target.value)}
                                >
                                    <option value="">—</option>
                                    {publishedPolicies.map((policy) => (
                                        <option key={policy.id} value={policy.id}>
                                            {policy.policy_ref} — {policy.title}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">Attestation</div>
                    <div className="card-body space-y-4">
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5"
                                checked={data.requires_attestation}
                                onChange={(e) => setData('requires_attestation', e.target.checked)}
                            />
                            <span>
                                Everyone in scope must attest to this policy
                                <span className="block text-xs text-gray-400">
                                    An attestation campaign opens automatically the moment the policy is published.
                                </span>
                            </span>
                        </label>

                        {data.requires_attestation && (
                            <div className="max-w-xs">
                                <label className="form-label">Attestation frequency</label>
                                <select
                                    className="form-select"
                                    value={data.attestation_frequency}
                                    onChange={(e) => setData('attestation_frequency', e.target.value)}
                                >
                                    <option value="">Select…</option>
                                    {attestationFrequencies.map((frequency) => (
                                        <option key={frequency} value={frequency}>
                                            {frequency.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.attestation_frequency} className="mt-1" />
                            </div>
                        )}

                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.mandatory_training}
                                onChange={(e) => setData('mandatory_training', e.target.checked)}
                            />
                            Mandatory training accompanies this policy
                        </label>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header flex items-center justify-between">
                        <span>Sections</span>
                        <button
                            type="button"
                            className="btn-secondary"
                            onClick={() => setData('sections', [...data.sections, { ...BLANK_SECTION }])}
                        >
                            Add section
                        </button>
                    </div>
                    <div className="card-body space-y-4">
                        {data.sections.map((section, index) => (
                            <div key={index} className="rounded-lg border border-gray-200 p-4">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        Section {index + 1}
                                    </span>
                                    {data.sections.length > 1 && (
                                        <button
                                            type="button"
                                            className="text-xs text-[var(--color-error)] hover:underline"
                                            onClick={() =>
                                                setData(
                                                    'sections',
                                                    data.sections.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>

                                <input
                                    className="form-input"
                                    placeholder="Heading"
                                    value={section.heading}
                                    onChange={(e) => setSection(index, 'heading', e.target.value)}
                                />
                                <InputError message={errors[`sections.${index}.heading`]} className="mt-1" />

                                <textarea
                                    className="form-input mt-3"
                                    rows={4}
                                    placeholder="Section text — this is what people attest to."
                                    value={section.body}
                                    onChange={(e) => setSection(index, 'body', e.target.value)}
                                />

                                <label className="mt-3 flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={section.is_mandatory}
                                        onChange={(e) => setSection(index, 'is_mandatory', e.target.checked)}
                                    />
                                    Mandatory requirement
                                </label>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <button type="submit" className="btn-primary" disabled={processing}>
                        {processing ? 'Saving…' : 'Save draft'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
