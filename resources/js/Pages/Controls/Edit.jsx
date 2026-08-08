import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import ControlForm from './Form';

export default function Edit({ control, categories, processes, units, users, types, natures, frequencies, cosoComponents, functionGroupings, controlLevels }) {
    const form = useForm({
        title: control.title ?? '',
        objective: control.objective ?? '',
        description: control.description ?? '',
        type: control.type,
        nature: control.nature,
        frequency: control.frequency,
        test_frequency: control.test_frequency,
        is_key_control: !!control.is_key_control,
        library_level: control.library_level ?? 'entity',
        function_grouping: control.function_grouping,
        control_level: control.control_level,
        is_distributable: !!control.is_distributable,
        category_id: control.category_id,
        coso_component: control.coso_component,
        process_id: control.process_id,
        unit_id: control.unit_id,
        department: control.department ?? '',
        owner_id: control.owner_id,
        control_documentation: control.control_documentation ?? '',
        notes: control.notes ?? '',
        change_reason: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('controls.update', control.id));
    };

    return (
        <AuthenticatedLayout header={`Amend ${control.control_ref}`}>
            <Head title={`Amend ${control.control_ref}`} />
            <PageHeader
                title={`Amend ${control.control_ref}`}
                subtitle="Amendments are versioned; an active control returns to Pending Approval"
                breadcrumbs={[
                    { label: 'Control Library', href: route('controls.index') },
                    { label: control.control_ref, href: route('controls.show', control.id) },
                    { label: 'Amend' },
                ]}
            />
            <ControlForm
                form={form}
                onSubmit={submit}
                submitLabel="Save amendment"
                controlRef={control.control_ref}
                requireChangeReason
                {...{ categories, processes, units, users, types, natures, frequencies, cosoComponents, functionGroupings, controlLevels }}
            />
        </AuthenticatedLayout>
    );
}
