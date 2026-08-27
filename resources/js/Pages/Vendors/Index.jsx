import DataTable from '@/Components/DataTable';
import FilterBar from '@/Components/FilterBar';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import SeverityBadge from '@/Components/SeverityBadge';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Activity, AlertCircle, CalendarClock, Plus } from 'lucide-react';

const CLASSIFICATION_LABELS = {
    none: 'No data access',
    internal: 'Internal data',
    confidential: 'Confidential',
    personal: 'Personal data',
    sensitive_personal: 'Sensitive personal data',
};

export default function Index({
    vendors,
    filters = {},
    criticalities = [],
    statuses = [],
    classifications = [],
    categories = [],
    entities = [],
    owners = [],
    obligations = [],
    stats = {},
    can = {},
}) {
    const [creating, setCreating] = useState(false);

    const columns = [
        {
            field: 'reference',
            label: 'Ref',
            render: (row) => <span className="font-mono text-xs font-semibold text-[var(--color-primary)]">{row.reference}</span>,
        },
        {
            field: 'legal_name',
            label: 'Third party',
            render: (row) => (
                <div className="max-w-xs">
                    <p className="font-medium text-[var(--color-text-primary)]">{row.legal_name}</p>
                    <p className="text-xs text-gray-400">
                        {row.category ?? 'Uncategorised'} · {row.jurisdiction}
                        {row.entity ? ` · ${row.entity.legal_name}` : ''}
                    </p>
                </div>
            ),
        },
        { field: 'criticality', label: 'Criticality', render: (row) => <SeverityBadge severity={row.criticality} /> },
        {
            field: 'current_assessment',
            label: 'Due diligence',
            render: (row) =>
                row.current_assessment ? (
                    <div className="text-xs">
                        <span
                            className={
                                row.current_assessment.status === 'Expired'
                                    ? 'font-semibold text-[var(--color-error)]'
                                    : 'text-[var(--color-text-primary)]'
                            }
                        >
                            {row.current_assessment.status}
                        </span>
                        {row.current_assessment.expires_at && (
                            <p className="text-gray-400">Review {formatDate(row.current_assessment.expires_at)}</p>
                        )}
                    </div>
                ) : (
                    <span className="text-xs font-semibold text-[var(--color-warning)]">Never assessed</span>
                ),
        },
        {
            field: 'data_access_classification',
            label: 'Data access',
            render: (row) => (
                <span className={`text-xs ${row.touches_personal_data ? 'font-semibold text-[var(--color-warning)]' : ''}`}>
                    {CLASSIFICATION_LABELS[row.data_access_classification]}
                </span>
            ),
        },
        {
            field: 'open_findings_count',
            label: 'Findings',
            render: (row) => (
                <span className={row.open_findings_count > 0 ? 'font-semibold text-[var(--color-error)]' : 'text-gray-400'}>
                    {row.open_findings_count}
                </span>
            ),
        },
        {
            field: 'status',
            label: 'Status',
            render: (row) => (
                <div className="space-y-1">
                    <StatusBadge status={row.status} />
                    {row.notification_outstanding && (
                        <p className="text-[10px] font-semibold uppercase text-[var(--color-warning)]">Notification due</p>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AuthenticatedLayout header="Third Parties">
            <Head title="Third Parties" />

            <PageHeader
                title="Third-party register"
                subtitle="Who the institution depends on, what they can reach, and whether the due diligence is still current"
                actions={
                    <>
                        <Link href={route('vendors.concentration')} className="btn-secondary">
                            Concentration risk
                        </Link>
                        {can.manage && <PrimaryButton onClick={() => setCreating(true)}><Plus className="h-4 w-4" strokeWidth={2} /> New third party</PrimaryButton>}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard icon={Activity} title="Active relationships" value={stats.active ?? 0} color="primary" />
                <StatCard icon={CalendarClock}
                    title="Lapsed reviews"
                    value={stats.expired_reviews ?? 0}
                    subtitle={`${stats.due_reviews ?? 0} due within 60 days`}
                    color="danger"
                    prominent={(stats.expired_reviews ?? 0) > 0}
                />
                <StatCard icon={AlertCircle}
                    title="Open findings"
                    value={stats.open_findings ?? 0}
                    subtitle={`${stats.outstanding_screenings ?? 0} screening hit(s) open`}
                    color="warning"
                />
                <StatCard icon={CalendarClock}
                    title="Contracts in notice"
                    value={stats.contracts_expiring ?? 0}
                    subtitle={`${stats.notifications_outstanding ?? 0} regulator notification(s) due`}
                    color="info"
                />
            </div>

            <FilterBar
                route="vendors.index"
                resource="vendors"
                currentFilters={filters}
                searchPlaceholder="Search third parties…"
                filters={[
                    { name: 'search', type: 'search', label: 'Search' },
                    { name: 'criticality', type: 'select', label: 'Criticality', options: criticalities },
                    { name: 'status', type: 'select', label: 'Status', options: statuses },
                    { name: 'category', type: 'select', label: 'Category', options: categories },
                    {
                        name: 'entity_id',
                        type: 'select',
                        label: 'Entity',
                        options: entities.map((e) => ({ value: e.id, label: e.legal_name })),
                    },
                    { name: 'personal_data_only', type: 'checkbox', label: 'Personal data only' },
                    { name: 'review_overdue', type: 'checkbox', label: 'Never assessed' },
                ]}
            />

            <div className="card">
                <DataTable
                    columns={columns}
                    data={vendors.data}
                    emptyMessage="No third parties match the filters."
                    onRowClick={(row) => router.visit(route('vendors.show', row.id))}
                />
                <Pagination paginator={vendors} />
            </div>

            <CreateModal
                show={creating}
                onClose={() => setCreating(false)}
                criticalities={criticalities}
                statuses={statuses}
                classifications={classifications}
                entities={entities}
                owners={owners}
                obligations={obligations}
            />
        </AuthenticatedLayout>
    );
}

function CreateModal({ show, onClose, criticalities, statuses, classifications, entities, owners, obligations }) {
    const form = useForm({
        legal_name: '',
        trading_name: '',
        registration_number: '',
        category: '',
        criticality: 'Medium',
        services_provided: '',
        jurisdiction: 'NG',
        processing_country: '',
        entity_id: '',
        relationship_start: '',
        annual_spend_minor: '',
        spend_currency: 'NGN',
        owner_id: '',
        regulator_notification_required: false,
        notification_obligation_id: '',
        data_access_classification: 'none',
        sub_processors: [],
        review_frequency_months: 12,
        status: 'Onboarding',
        services_provided_rich: null,
    });

    const addSubProcessor = () =>
        form.setData('sub_processors', [...form.data.sub_processors, { name: '', country: '', service: '' }]);

    const setSubProcessor = (index, field, value) => {
        const next = [...form.data.sub_processors];
        next[index] = { ...next[index], [field]: value };
        form.setData('sub_processors', next);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('vendors.store'), { onSuccess: onClose });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="3xl" title="Register a third party">
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Legal name" required />
                        <TextInput value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} />
                        {form.errors.legal_name && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.legal_name}</p>}
                    </div>
                    <div>
                        <InputLabel value="Trading name" />
                        <TextInput value={form.data.trading_name} onChange={(e) => form.setData('trading_name', e.target.value)} />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Category" />
                        <TextInput
                            value={form.data.category}
                            onChange={(e) => form.setData('category', e.target.value)}
                            placeholder="Payment processing"
                        />
                    </div>
                    <div>
                        <InputLabel value="Criticality" required />
                        <SelectInput value={form.data.criticality} onChange={(e) => form.setData('criticality', e.target.value)}>
                            {criticalities.map((c) => (
                                <option key={c} value={c}>
                                    {c}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Incorporated in" required />
                        <TextInput
                            maxLength={2}
                            value={form.data.jurisdiction}
                            onChange={(e) => form.setData('jurisdiction', e.target.value.toUpperCase())}
                        />
                    </div>
                    <div>
                        <InputLabel value="Processes data in" />
                        <TextInput
                            maxLength={2}
                            value={form.data.processing_country}
                            onChange={(e) => form.setData('processing_country', e.target.value.toUpperCase())}
                        />
                    </div>
                </div>

                <div>
                    <InputLabel value="Services provided" />
                    <RichTextEditor
                        value={form.data.services_provided_rich ?? form.data.services_provided}
                        onChange={(doc, plain) => form.setData((d) => ({ ...d, services_provided: plain, services_provided_rich: doc }))}
                        tools="minimal"
                        minHeight={90}
                    />
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <InputLabel value="Relationship owner" />
                        <SelectInput value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                            <option value="">—</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Entity" />
                        <SelectInput value={form.data.entity_id} onChange={(e) => form.setData('entity_id', e.target.value)}>
                            <option value="">Group-wide</option>
                            {entities.map((entity) => (
                                <option key={entity.id} value={entity.id}>
                                    {entity.legal_name}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Annual spend" />
                        <TextInput
                            type="number"
                            min="0"
                            value={form.data.annual_spend_minor === '' ? '' : form.data.annual_spend_minor / 100}
                            onChange={(e) =>
                                form.setData(
                                    'annual_spend_minor',
                                    e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100),
                                )
                            }
                        />
                    </div>
                    <div>
                        <InputLabel value="Currency" required />
                        <SelectInput value={form.data.spend_currency} onChange={(e) => form.setData('spend_currency', e.target.value)}>
                            {['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'].map((code) => (
                                <option key={code} value={code}>
                                    {code}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Data access" required />
                        <SelectInput
                            value={form.data.data_access_classification}
                            onChange={(e) => form.setData('data_access_classification', e.target.value)}
                        >
                            {classifications.map((c) => (
                                <option key={c} value={c}>
                                    {CLASSIFICATION_LABELS[c]}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel value="Review every (months)" required />
                        <TextInput
                            type="number"
                            min="1"
                            max="60"
                            value={form.data.review_frequency_months}
                            onChange={(e) => form.setData('review_frequency_months', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Status" required />
                        <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </SelectInput>
                    </div>
                </div>

                <div className="rounded-lg border border-gray-100 p-3">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300"
                            checked={form.data.regulator_notification_required}
                            onChange={(e) => form.setData('regulator_notification_required', e.target.checked)}
                        />
                        This outsourcing requires a regulator notification
                    </label>
                    {form.data.regulator_notification_required && (
                        <div className="mt-2">
                            <InputLabel value="Against which obligation" />
                            <SelectInput
                                value={form.data.notification_obligation_id}
                                onChange={(e) => form.setData('notification_obligation_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {obligations.map((obligation) => (
                                    <option key={obligation.id} value={obligation.id}>
                                        {obligation.obligation_ref} — {obligation.title}
                                        {obligation.verification_status === 'unverified' ? ' (unverified)' : ''}
                                    </option>
                                ))}
                            </SelectInput>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Whether an outsourcing is material enough to notify is the institution's decision. The
                                obligation, its citation and its deadline live in the obligation register.
                            </p>
                        </div>
                    )}
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <InputLabel value="Sub-processors" />
                        <button type="button" className="text-xs font-semibold text-[var(--color-primary)]" onClick={addSubProcessor}>
                            <Plus className="h-4 w-4" strokeWidth={2} /> Add
                        </button>
                    </div>
                    <div className="space-y-2">
                        {form.data.sub_processors.map((processor, index) => (
                            <div key={index} className="grid grid-cols-3 gap-2">
                                <TextInput
                                    placeholder="Name"
                                    value={processor.name}
                                    onChange={(e) => setSubProcessor(index, 'name', e.target.value)}
                                />
                                <TextInput
                                    placeholder="Service"
                                    value={processor.service}
                                    onChange={(e) => setSubProcessor(index, 'service', e.target.value)}
                                />
                                <TextInput
                                    placeholder="Country"
                                    maxLength={2}
                                    value={processor.country}
                                    onChange={(e) => setSubProcessor(index, 'country', e.target.value.toUpperCase())}
                                />
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton disabled={form.processing}>Register</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
