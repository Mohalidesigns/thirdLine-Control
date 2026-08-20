import ObligationForm from '@/Components/ObligationForm';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Create(options) {
    const form = useForm({
        regulator_id: '',
        obligation_ref: '',
        title: '',
        description: '',
        obligation_type: 'filing',
        trigger_type: 'calendar',
        frequency: 'annual',
        due_rule: { type: 'fixed_date', month: 3, day: 31, roll: 'next_business_day' },
        grace_period_days: 0,
        penalty_description: '',
        penalty_amount_minor: null,
        penalty_fixed_amount_minor: null,
        penalty_currency: null,
        penalty_basis: null,
        legal_reference: '',
        source_url: '',
        effective_from: '',
        effective_to: '',
        // R10: a new obligation starts unverified until someone checks the source.
        verification_status: 'unverified',
        is_active: true,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('obligations.store'));
    };

    return (
        <AuthenticatedLayout header="Add obligation">
            <Head title="Add obligation" />

            <PageHeader
                breadcrumbs={[{ label: 'Obligations', href: route('obligations.index') }, { label: 'New' }]}
                title="Add a regulatory obligation"
                subtitle="Define what is owed, to whom, how often, and what missing it costs"
                actions={
                    <Link href={route('obligations.index')} className="btn-secondary">
                        Cancel
                    </Link>
                }
            />

            <form onSubmit={submit}>
                <ObligationForm form={form} options={options} submitLabel="Create obligation" />
            </form>
        </AuthenticatedLayout>
    );
}
