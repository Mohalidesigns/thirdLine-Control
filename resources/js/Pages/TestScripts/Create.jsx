import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

const EMPTY_ITEM = { question: '', guidance: '', expected_result: '', is_mandatory: true, default_severity_on_fail: 'Medium' };

export default function Create({ controls = [], preselectedControlId = null }) {
    const { data, setData, post, processing, errors } = useForm({
        control_id: preselectedControlId ?? '',
        title: '',
        objective: '',
        sampling_guidance: '',
        check_items: [{ ...EMPTY_ITEM }],
    });

    const updateItem = (index, field, value) => {
        const items = [...data.check_items];
        items[index] = { ...items[index], [field]: value };
        setData('check_items', items);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('test-scripts.store'));
    };

    return (
        <AuthenticatedLayout header="New Test Script">
            <Head title="New Test Script" />

            <PageHeader
                title="Build a test script"
                subtitle="An ordered checklist the tester works through — scripts require approval before use"
                breadcrumbs={[{ label: 'Test Scripts', href: route('test-scripts.index') }, { label: 'New' }]}
            />

            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6">
                <div className="card">
                    <div className="card-body space-y-4">
                        <div>
                            <InputLabel value="Control" required />
                            <SelectInput value={data.control_id} onChange={(e) => setData('control_id', e.target.value)}>
                                <option value="">— Select control —</option>
                                {controls.map((control) => (
                                    <option key={control.id} value={control.id}>
                                        {control.control_ref} — {control.title}
                                    </option>
                                ))}
                            </SelectInput>
                            <InputError message={errors.control_id} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Script title" required />
                            <TextInput value={data.title} onChange={(e) => setData('title', e.target.value)} />
                            <InputError message={errors.title} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Objective" />
                            <TextArea value={data.objective} onChange={(e) => setData('objective', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Sampling guidance" />
                            <TextArea
                                value={data.sampling_guidance}
                                onChange={(e) => setData('sampling_guidance', e.target.value)}
                                placeholder="e.g. Select 25 transactions at random across the period, covering all branches…"
                            />
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Check items ({data.check_items.length})</h3>
                        <SecondaryButton onClick={() => setData('check_items', [...data.check_items, { ...EMPTY_ITEM }])}>
                            + Add item
                        </SecondaryButton>
                    </div>
                    <div className="card-body space-y-5">
                        <InputError message={errors.check_items} />
                        {data.check_items.map((item, index) => (
                            <div key={index} className="rounded-lg border border-gray-200 p-4">
                                <div className="mb-3 flex items-center justify-between">
                                    <p className="text-sm font-semibold text-[var(--color-text-secondary)]">Item {index + 1}</p>
                                    {data.check_items.length > 1 && (
                                        <button
                                            type="button"
                                            className="text-xs text-[var(--color-error)] hover:underline"
                                            onClick={() => setData('check_items', data.check_items.filter((_, i) => i !== index))}
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>
                                <div className="space-y-3">
                                    <div>
                                        <InputLabel value="Question" required />
                                        <TextArea rows={2} value={item.question} onChange={(e) => updateItem(index, 'question', e.target.value)} />
                                        <InputError message={errors[`check_items.${index}.question`]} className="mt-1" />
                                    </div>
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div>
                                            <InputLabel value="Guidance" />
                                            <TextArea rows={2} value={item.guidance} onChange={(e) => updateItem(index, 'guidance', e.target.value)} />
                                        </div>
                                        <div>
                                            <InputLabel value="Expected result" />
                                            <TextArea rows={2} value={item.expected_result} onChange={(e) => updateItem(index, 'expected_result', e.target.value)} />
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-6">
                                        <div className="w-56">
                                            <InputLabel value="Severity if failed" required />
                                            <SelectInput
                                                value={item.default_severity_on_fail}
                                                onChange={(e) => updateItem(index, 'default_severity_on_fail', e.target.value)}
                                            >
                                                {['Critical', 'High', 'Medium', 'Low'].map((s) => <option key={s} value={s}>{s}</option>)}
                                            </SelectInput>
                                        </div>
                                        <label className="mt-5 flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                                checked={!!item.is_mandatory}
                                                onChange={(e) => updateItem(index, 'is_mandatory', e.target.checked)}
                                            />
                                            <span className="text-sm text-gray-600">Mandatory</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton onClick={() => window.history.back()}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Submit for approval</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
