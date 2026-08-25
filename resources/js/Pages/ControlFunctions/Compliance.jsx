import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import StatCard from '@/Components/StatCard';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CalendarCheck, Percent } from 'lucide-react';
import { useState } from 'react';

/**
 * CR-03 §E.4 report 2 — expected against actual, per function.
 *
 * This is the screen that answers "prove this control was performed at
 * its stated frequency for the last twelve months", which is the
 * question an examiner actually asks.
 */
export default function Compliance({ rows = [], units = [], range = {} }) {
    const [form, setForm] = useState({ from: range.from ?? '', to: range.to ?? '' });

    const apply = (next) => {
        const merged = { ...form, ...next };
        setForm(merged);
        router.get(route('control-functions.compliance'), merged, { preserveState: true, replace: true });
    };

    const totals = rows.reduce(
        (acc, row) => ({
            expected: acc.expected + row.expected,
            actual: acc.actual + row.actual,
            gap: acc.gap + row.gap,
        }),
        { expected: 0, actual: 0, gap: 0 },
    );

    const rate = totals.expected > 0 ? Math.round((totals.actual / totals.expected) * 100) : 0;

    return (
        <AuthenticatedLayout header="Control Functions">
            <Head title="Frequency compliance" />

            <PageHeader
                title="Frequency compliance"
                subtitle="Expected occurrences against actual, for every control function in the window"
            />

            <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard title="Expected" value={totals.expected} icon={CalendarCheck} tone="blue" />
                <StatCard title="Generated" value={totals.actual} icon={CalendarCheck} tone="blue" />
                <StatCard title="Gap" value={totals.gap} icon={AlertTriangle} tone={totals.gap > 0 ? 'red' : 'emerald'} />
                <StatCard title="Coverage" value={`${rate}%`} icon={Percent} tone={rate >= 95 ? 'emerald' : 'amber'} />
            </div>

            <div className="card mb-5">
                <div className="card-body flex flex-wrap items-end gap-3">
                    <div>
                        <label className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]" htmlFor="from">From</label>
                        <TextInput id="from" type="date" value={form.from} onChange={(e) => apply({ from: e.target.value })} />
                    </div>
                    <div>
                        <label className="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]" htmlFor="to">To</label>
                        <TextInput id="to" type="date" value={form.to} onChange={(e) => apply({ to: e.target.value })} />
                    </div>
                </div>
            </div>

            <div className="card mb-5">
                <div className="card-body pb-0">
                    <h2 className="text-lg font-semibold">Completion by sub-unit</h2>
                </div>
                <DataTable
                    columns={[
                        { field: 'unit_name', label: 'Sub-unit' },
                        { field: 'total', label: 'Tasks', width: '7rem' },
                        { field: 'completed', label: 'Completed', width: '8rem' },
                        {
                            field: 'overdue',
                            label: 'Overdue',
                            width: '7rem',
                            render: (row) => (
                                <span className={row.overdue > 0 ? 'font-semibold text-red-600' : ''}>{row.overdue}</span>
                            ),
                        },
                        {
                            field: 'completion_rate',
                            label: 'Completion',
                            width: '14rem',
                            render: (row) => <ProgressBar value={row.completion_rate} />,
                        },
                    ]}
                    data={units}
                    emptyMessage="No control tasks in this window."
                />
            </div>

            <div className="card">
                <div className="card-body pb-0">
                    <h2 className="text-lg font-semibold">By function</h2>
                    <p className="text-sm text-[var(--color-text-secondary)]">
                        Expected counts the periods in the window across every desk or branch that executes the function.
                        Event-driven and observation functions have no expected count — nobody can say a CBN circular
                        should have been published four times.
                    </p>
                </div>
                <DataTable
                    columns={[
                        { field: 'control_ref', label: 'Reference', width: '9rem' },
                        { field: 'title', label: 'Function' },
                        { field: 'unit', label: 'Sub-unit', width: '12rem' },
                        { field: 'frequency', label: 'Frequency', width: '10rem' },
                        { field: 'expected', label: 'Expected', width: '7rem' },
                        { field: 'actual', label: 'Actual', width: '7rem' },
                        { field: 'completed', label: 'Completed', width: '8rem' },
                        {
                            field: 'gap',
                            label: 'Gap',
                            width: '6rem',
                            render: (row) => (
                                <span className={row.gap > 0 ? 'font-semibold text-red-600' : 'text-emerald-600'}>{row.gap}</span>
                            ),
                        },
                    ]}
                    data={rows}
                    emptyMessage="No control functions in this window."
                />
            </div>
        </AuthenticatedLayout>
    );
}
