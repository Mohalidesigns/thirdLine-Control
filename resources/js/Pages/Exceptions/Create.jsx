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

export default function Create({ controls = [], users = [], units = [], severities = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        root_cause: '',
        severity: 'Medium',
        control_id: '',
        owner_id: '',
        responsible_party_id: '',
        unit_id: '',
        target_closure_date: '',
    });

    const onControlChange = (controlId) => {
        const control = controls.find((c) => String(c.id) === String(controlId));
        setData((prev) => ({
            ...prev,
            control_id: controlId,
            owner_id: control?.owner_id ?? prev.owner_id,
            unit_id: control?.unit_id ?? prev.unit_id,
        }));
    };

    return (
        <AuthenticatedLayout header="Raise Exception">
            <Head title="Raise Exception" />

            <PageHeader
                title="Raise an exception manually"
                subtitle="Test failures and spot check findings raise exceptions automatically — use this for other observations"
                breadcrumbs={[{ label: 'Exceptions', href: route('exceptions.index') }, { label: 'New' }]}
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('exceptions.store'));
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
                            <InputLabel value="Description" />
                            <TextArea value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel value="Root cause" />
                            <TextArea rows={2} value={data.root_cause} onChange={(e) => setData('root_cause', e.target.value)} />
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Severity" required />
                                <SelectInput value={data.severity} onChange={(e) => setData('severity', e.target.value)}>
                                    {severities.map((s) => <option key={s} value={s}>{s}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Target closure date" required />
                                <TextInput type="date" value={data.target_closure_date} onChange={(e) => setData('target_closure_date', e.target.value)} />
                                <InputError message={errors.target_closure_date} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Related control" />
                                <SelectInput value={data.control_id} onChange={(e) => onControlChange(e.target.value)}>
                                    <option value="">— None —</option>
                                    {controls.map((control) => (
                                        <option key={control.id} value={control.id}>
                                            {control.control_ref} — {control.title}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Unit" />
                                <SelectInput value={data.unit_id} onChange={(e) => setData('unit_id', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Control owner" />
                                <SelectInput value={data.owner_id} onChange={(e) => setData('owner_id', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <InputLabel value="Responsible party" />
                                <SelectInput value={data.responsible_party_id} onChange={(e) => setData('responsible_party_id', e.target.value)}>
                                    <option value="">— Select —</option>
                                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </SelectInput>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton onClick={() => window.history.back()}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={processing}>Raise exception</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
