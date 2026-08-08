import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Schedules({
    schedules = [],
    definitions = [],
    deliveryMethods = [],
    formats = [],
    roles = [],
    users = [],
    presets = {},
}) {
    const form = useForm({
        report_definition_id: definitions[0]?.id ?? '',
        name: '',
        cron: Object.keys(presets)[0] ?? '0 7 * * 1',
        timezone: 'Africa/Lagos',
        parameters: { period: 'last_quarter' },
        output_format: 'pdf',
        recipients: { role_names: [], user_ids: [] },
        delivery_method: 'in_app',
        is_active: true,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('reports.schedules.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('name'),
        });
    };

    return (
        <AuthenticatedLayout header="Report schedules">
            <Head title="Report schedules" />

            <PageHeader
                title="Report schedules"
                subtitle="Unattended generation and distribution. Each schedule keeps its own timezone."
                breadcrumbs={[{ label: 'Report library', href: route('reports.library') }, { label: 'Schedules' }]}
                actions={<Link href={route('reports.runs')} className="btn-secondary">Run history</Link>}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <section className="card lg:col-span-2">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Active schedules</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Schedule</th>
                                    <th>Report</th>
                                    <th>Cadence</th>
                                    <th>Next run</th>
                                    <th>Last</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {schedules.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-center text-sm text-gray-400">
                                            No schedules yet.
                                        </td>
                                    </tr>
                                )}
                                {schedules.map((schedule) => (
                                    <tr key={schedule.id}>
                                        <td>
                                            <span className="font-medium text-[var(--color-text-primary)]">
                                                {schedule.name}
                                            </span>
                                            <p className="text-xs text-gray-400">
                                                {schedule.delivery_method.replace(/_/g, ' ')} ·{' '}
                                                {schedule.output_format.toUpperCase()}
                                            </p>
                                        </td>
                                        <td>{schedule.definition?.name}</td>
                                        <td>
                                            <span className="font-mono text-xs">{schedule.cron}</span>
                                            <p className="text-xs text-gray-400">{schedule.timezone}</p>
                                        </td>
                                        <td>{formatDate(schedule.next_run_at)}</td>
                                        <td>
                                            {schedule.last_status
                                                ? <StatusBadge status={schedule.last_status} />
                                                : <span className="text-xs text-gray-400">Never</span>}
                                        </td>
                                        <td className="whitespace-nowrap text-right">
                                            <button
                                                type="button"
                                                onClick={() => router.post(route('reports.schedules.run-now', schedule.id))}
                                                className="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                            >
                                                Run now
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => router.delete(route('reports.schedules.destroy', schedule.id))}
                                                className="ms-3 text-sm text-[var(--color-error)] hover:underline"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="card lg:col-span-1">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">New schedule</h2>
                    </div>
                    <form onSubmit={submit} className="card-body space-y-3">
                        <label className="block">
                            <span className="form-label">Report</span>
                            <select
                                className="form-select"
                                value={form.data.report_definition_id}
                                onChange={(event) => form.setData('report_definition_id', event.target.value)}
                            >
                                {definitions.map((definition) => (
                                    <option key={definition.id} value={definition.id}>
                                        {definition.name} ({definition.confidentiality})
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="form-label">Name</span>
                            <input
                                className="form-input"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                            {form.errors.name && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.name}</p>}
                        </label>

                        <label className="block">
                            <span className="form-label">Cadence</span>
                            <select
                                className="form-select"
                                value={form.data.cron}
                                onChange={(event) => form.setData('cron', event.target.value)}
                            >
                                {Object.entries(presets).map(([expression, label]) => (
                                    <option key={expression} value={expression}>{label}</option>
                                ))}
                            </select>
                            {form.errors.cron && <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.cron}</p>}
                        </label>

                        <label className="block">
                            <span className="form-label">Timezone</span>
                            <input
                                className="form-input"
                                value={form.data.timezone}
                                onChange={(event) => form.setData('timezone', event.target.value)}
                            />
                        </label>

                        <div className="grid grid-cols-2 gap-2">
                            <label className="block">
                                <span className="form-label">Format</span>
                                <select
                                    className="form-select"
                                    value={form.data.output_format}
                                    onChange={(event) => form.setData('output_format', event.target.value)}
                                >
                                    {formats.map((format) => (
                                        <option key={format} value={format}>{format.toUpperCase()}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block">
                                <span className="form-label">Delivery</span>
                                <select
                                    className="form-select"
                                    value={form.data.delivery_method}
                                    onChange={(event) => form.setData('delivery_method', event.target.value)}
                                >
                                    {deliveryMethods.map((method) => (
                                        <option key={method} value={method}>{method.replace(/_/g, ' ')}</option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <fieldset>
                            <legend className="form-label">Recipient roles</legend>
                            <div className="flex flex-wrap gap-2">
                                {roles.map((role) => (
                                    <label key={role} className="flex items-center gap-1.5 text-xs">
                                        <input
                                            type="checkbox"
                                            checked={form.data.recipients.role_names.includes(role)}
                                            onChange={(event) => form.setData('recipients', {
                                                ...form.data.recipients,
                                                role_names: event.target.checked
                                                    ? [...form.data.recipients.role_names, role]
                                                    : form.data.recipients.role_names.filter((one) => one !== role),
                                            })}
                                        />
                                        {role}
                                    </label>
                                ))}
                            </div>
                            <p className="mt-1 text-xs text-[var(--color-text-secondary)]">
                                Recipients who could not open the report at its classification are skipped, and
                                the skip is recorded on the run.
                            </p>
                        </fieldset>

                        <button type="submit" className="btn-primary w-full" disabled={form.processing}>
                            Create schedule
                        </button>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
