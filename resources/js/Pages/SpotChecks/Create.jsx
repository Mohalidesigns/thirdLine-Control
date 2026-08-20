import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import RichTextEditor from '@/Components/RichTextEditor';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Create({ units = [], processes = [], controls = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        scope_description: '',
        scope_description_rich: null,
        unit_id: '',
        process_id: '',
        control_ids: [],
        is_surprise: false,
        date_conducted: new Date().toISOString().slice(0, 10),
    });

    const toggleControl = (id) => {
        setData(
            'control_ids',
            data.control_ids.includes(id)
                ? data.control_ids.filter((c) => c !== id)
                : [...data.control_ids, id],
        );
    };

    return (
        <AuthenticatedLayout header="New Spot Check">
            <Head title="New Spot Check" />

            <PageHeader
                title="Initiate a spot check"
                subtitle="An ad-hoc review against controls, a process, or a branch/unit"
                breadcrumbs={[{ label: 'Spot Checks', href: route('spot-checks.index') }, { label: 'New' }]}
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('spot-checks.store'));
                }}
                className="mx-auto max-w-4xl space-y-6"
            >
                <div className="card">
                    <div className="card-body space-y-4">
                        <div>
                            <InputLabel value="Title" required />
                            <TextInput value={data.title} onChange={(e) => setData('title', e.target.value)} />
                            <InputError message={errors.title} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Scope description" />
                            <RichTextEditor
                                value={data.scope_description_rich ?? data.scope_description}
                                onChange={(doc, plain) => setData((d) => ({ ...d, scope_description: plain, scope_description_rich: doc }))}
                                tools="default"
                                minHeight={110}
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <InputLabel value="Unit / branch" />
                                <SelectInput value={data.unit_id} onChange={(e) => setData('unit_id', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Process" />
                                <SelectInput value={data.process_id} onChange={(e) => setData('process_id', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {processes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Date" required />
                                <TextInput type="date" value={data.date_conducted} onChange={(e) => setData('date_conducted', e.target.value)} />
                                <InputError message={errors.date_conducted} className="mt-1" />
                            </div>
                        </div>
                        <label className="flex items-center gap-2">
                            <Checkbox checked={data.is_surprise} onChange={(e) => setData('is_surprise', e.target.checked)} />
                            <span className="text-sm text-gray-600">
                                Surprise check — the target unit is not notified in advance
                            </span>
                        </label>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold">Controls in scope ({data.control_ids.length})</h3>
                    </div>
                    <div className="card-body grid max-h-72 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
                        {controls.map((control) => (
                            <label key={control.id} className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50">
                                <Checkbox
                                    checked={data.control_ids.includes(control.id)}
                                    onChange={() => toggleControl(control.id)}
                                />
                                <span className="text-sm">
                                    <span className="font-mono text-xs text-gray-500">{control.control_ref}</span> {control.title}
                                </span>
                            </label>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton onClick={() => window.history.back()}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Start spot check</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
