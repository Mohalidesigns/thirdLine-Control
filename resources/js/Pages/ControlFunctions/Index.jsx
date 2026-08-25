import DataTable from '@/Components/DataTable';
import FrequencyBadge from '@/Components/FrequencyBadge';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarCheck, ClipboardList, ListChecks, Upload } from 'lucide-react';
import { useState } from 'react';

/**
 * CR-03 §E.2: the departmental control function catalogue — unit,
 * function, line count, frequency, next due.
 */
export default function Index({
    functions = { data: [], links: [] },
    filters = {},
    units = [],
    frequencies = [],
    summary = {},
    can = {},
}) {
    const [form, setForm] = useState({
        search: filters.search ?? '',
        unit: filters.unit ?? '',
        frequency: filters.frequency ?? '',
        status: filters.status ?? '',
    });

    const apply = (next) => {
        const merged = { ...form, ...next };
        setForm(merged);
        router.get(route('control-functions.index'), merged, { preserveState: true, replace: true });
    };

    const columns = [
        {
            field: 'reference',
            label: 'Reference',
            width: '9rem',
            render: (row) => (
                <Link href={route('control-functions.show', row.id)} className="font-medium text-[var(--color-primary)] hover:underline">
                    {row.reference}
                </Link>
            ),
        },
        {
            field: 'title',
            label: 'Function',
            render: (row) => (
                <div>
                    <p className="font-medium text-[var(--color-text-primary)]">{row.title}</p>
                    <p className="text-xs text-[var(--color-text-secondary)]">
                        {row.unit?.name ?? '—'}
                        {row.entity ? ` · ${row.entity.name}` : ''}
                    </p>
                </div>
            ),
        },
        {
            field: 'line_count',
            label: 'Lines',
            width: '5rem',
            render: (row) => <span className="tabular-nums">{row.line_count}</span>,
        },
        {
            field: 'entity_count',
            label: 'Executed by',
            width: '8rem',
            render: (row) => (
                <span className="text-sm text-[var(--color-text-secondary)]">
                    {row.entity_count === 0 ? 'Unassigned' : `${row.entity_count} ${row.entity_count === 1 ? 'desk' : 'desks / branches'}`}
                </span>
            ),
        },
        {
            field: 'frequency',
            label: 'Frequency of Activity',
            width: '12rem',
            render: (row) => <FrequencyBadge frequency={row.frequency} raw={row.frequency_raw} />,
        },
        {
            field: 'next_due',
            label: 'Next due',
            width: '8rem',
            render: (row) => row.next_due ?? <span className="text-gray-400">—</span>,
        },
        {
            field: 'status',
            label: 'Status',
            width: '8rem',
            render: (row) => <StatusBadge status={row.status} />,
        },
    ];

    return (
        <AuthenticatedLayout header="Control Functions">
            <Head title="Control Functions" />

            <PageHeader
                title="Departmental control functions"
                subtitle="Every checklist the control function works to, and the rhythm that manufactures it"
                actions={
                    <div className="flex gap-2">
                        <Link href={route('control-functions.compliance')}>
                            <PrimaryButton type="button" className="!bg-transparent !text-[var(--color-primary)] ring-1 ring-[var(--color-primary)]">
                                Frequency compliance
                            </PrimaryButton>
                        </Link>
                        {can.import && (
                            <Link href={route('control-functions.import.index')}>
                                <PrimaryButton type="button">
                                    <Upload className="me-1.5 h-4 w-4" aria-hidden="true" />
                                    Import workbook
                                </PrimaryButton>
                            </Link>
                        )}
                    </div>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <StatCard title="Functions" value={summary.functions ?? 0} icon={ClipboardList} tone="blue" />
                <StatCard title="Checklist lines" value={summary.lines ?? 0} icon={ListChecks} tone="blue" />
                <StatCard title="Open tasks" value={summary.open_tasks ?? 0} icon={CalendarCheck} tone="blue" />
                <StatCard title="Due today" value={summary.due_today ?? 0} icon={CalendarCheck} tone="amber" />
                <StatCard
                    title="Overdue"
                    value={summary.overdue ?? 0}
                    icon={AlertTriangle}
                    tone={summary.overdue > 0 ? 'red' : 'emerald'}
                />
            </div>

            <div className="card mb-5">
                <div className="card-body grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <TextInput
                        value={form.search}
                        placeholder="Search function or reference"
                        onChange={(e) => setForm({ ...form, search: e.target.value })}
                        onKeyDown={(e) => e.key === 'Enter' && apply({})}
                    />
                    <SelectInput value={form.unit} onChange={(e) => apply({ unit: e.target.value })}>
                        <option value="">All sub-units</option>
                        {units.map((unit) => (
                            <option key={unit.id} value={unit.id}>{unit.name}</option>
                        ))}
                    </SelectInput>
                    <SelectInput value={form.frequency} onChange={(e) => apply({ frequency: e.target.value })}>
                        <option value="">All frequencies</option>
                        {frequencies.map((frequency) => (
                            <option key={frequency.code} value={frequency.code}>{frequency.label}</option>
                        ))}
                    </SelectInput>
                    <SelectInput value={form.status} onChange={(e) => apply({ status: e.target.value })}>
                        <option value="">All statuses</option>
                        {['Active', 'Draft', 'Under Review', 'Retired'].map((status) => (
                            <option key={status} value={status}>{status}</option>
                        ))}
                    </SelectInput>
                </div>
            </div>

            <div className="card">
                <DataTable
                    columns={columns}
                    data={functions.data}
                    emptyMessage="No control functions yet — import the departmental checklist workbook to populate the catalogue."
                />
            </div>

            <Pagination paginator={functions} />
        </AuthenticatedLayout>
    );
}
