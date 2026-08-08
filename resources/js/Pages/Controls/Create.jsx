import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import ControlForm from './Form';

export default function Create({ nextRef, categories, processes, units, users, types, natures, frequencies, cosoComponents, functionGroupings, controlLevels }) {
    const form = useForm({
        title: '',
        objective: '',
        description: '',
        type: types[0],
        nature: natures[0],
        frequency: 'Monthly',
        test_frequency: null,
        is_key_control: false,
        library_level: 'entity',
        function_grouping: null,
        control_level: null,
        is_distributable: false,
        category_id: null,
        coso_component: null,
        process_id: null,
        unit_id: null,
        department: '',
        owner_id: null,
        control_documentation: '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('controls.store'));
    };

    return (
        <AuthenticatedLayout header="Add Control">
            <Head title="Add Control" />
            <PageHeader
                title="Add Control"
                subtitle="New controls start as drafts and go through maker-checker approval"
                breadcrumbs={[{ label: 'Control Library', href: route('controls.index') }, { label: 'Create New' }]}
            />
            <ControlForm
                form={form}
                onSubmit={submit}
                submitLabel="Create Control"
                controlRef={nextRef}
                {...{ categories, processes, units, users, types, natures, frequencies, cosoComponents, functionGroupings, controlLevels }}
            />
        </AuthenticatedLayout>
    );
}
