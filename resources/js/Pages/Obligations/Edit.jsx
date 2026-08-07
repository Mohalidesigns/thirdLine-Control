import ObligationForm from '@/Components/ObligationForm';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Edit({ obligation, ...options }) {
    const form = useForm({
        regulator_id: obligation.regulator_id,
        framework_id: obligation.framework_id,
        requirement_id: obligation.requirement_id,
        obligation_ref: obligation.obligation_ref,
        title: obligation.title,
        description: obligation.description ?? '',
        obligation_type: obligation.obligation_type,
        applies_to: obligation.applies_to,
        trigger_type: obligation.trigger_type,
        frequency: obligation.frequency,
        due_rule: obligation.due_rule ?? { type: 'fixed_date' },
        grace_period_days: obligation.grace_period_days ?? 0,
        penalty_description: obligation.penalty_description ?? '',
        penalty_amount_minor: obligation.penalty_amount_minor,
        penalty_currency: obligation.penalty_currency,
        penalty_basis: obligation.penalty_basis,
        legal_reference: obligation.legal_reference ?? '',
        source_url: obligation.source_url ?? '',
        effective_from: obligation.effective_from ?? '',
        effective_to: obligation.effective_to ?? '',
        verification_status: obligation.verification_status,
        is_active: obligation.is_active,
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('obligations.update', obligation.id));
    };

    return (
        <AuthenticatedLayout header={obligation.obligation_ref}>
            <Head title={`Edit ${obligation.obligation_ref}`} />

            <PageHeader
                breadcrumbs={[
                    { label: 'Obligations', href: route('obligations.index') },
                    { label: obligation.obligation_ref, href: route('obligations.show', obligation.id) },
                    { label: 'Edit' },
                ]}
                title={`Edit ${obligation.obligation_ref}`}
                subtitle={obligation.title}
                actions={
                    <Link href={route('obligations.show', obligation.id)} className="btn-secondary">
                        Cancel
                    </Link>
                }
            />

            <form onSubmit={submit}>
                <ObligationForm form={form} options={options} submitLabel="Save changes" />
            </form>
        </AuthenticatedLayout>
    );
}
