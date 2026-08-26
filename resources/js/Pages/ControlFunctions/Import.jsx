import DataTable from '@/Components/DataTable';
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Upload } from 'lucide-react';

/**
 * CR-03 §D: upload → dry-run diff → commit.
 *
 * The two steps are separate on purpose. One commit rewrites the whole
 * function register in a single transaction, and an unrecognised
 * frequency blocks it outright rather than quietly becoming Monthly.
 */
export default function Import({ imports = [], pending = null, sheets = [] }) {
    const form = useForm({ file: null });

    const upload = (e) => {
        e.preventDefault();
        form.post(route('control-functions.import.store'), { forceFormData: true });
    };

    const commit = () => router.post(route('control-functions.import.commit', pending.id), {}, { preserveScroll: true });
    const discard = () => router.delete(route('control-functions.import.discard', pending.id), { preserveScroll: true });

    const blocked = pending && pending.rows_unresolved > 0;

    return (
        <AuthenticatedLayout header="Control Functions">
            <Head title="Import control function checklists" />

            <PageHeader
                title="Import the departmental checklist workbook"
                subtitle="Upload, review what would change, then commit. Nothing is written until you do."
            />

            <div className="card mb-5">
                <form onSubmit={upload} className="card-body">
                    <InputLabel htmlFor="file" value="Checklist workbook (.xlsx)" />
                    <input
                        id="file"
                        type="file"
                        accept=".xlsx,.xls"
                        onChange={(e) => form.setData('file', e.target.files[0])}
                        className="form-input mt-1"
                    />
                    <InputError message={form.errors.file} className="mt-1" />

                    <p className="mt-2 text-xs text-[var(--color-text-secondary)]">
                        Expected sheets: {sheets.join(', ')}. Units and functions may be written once and left blank on
                        continuation rows — the importer carries them down.
                    </p>

                    <div className="mt-4">
                        <PrimaryButton disabled={form.processing || !form.data.file}>
                            <Upload className="me-1.5 h-4 w-4" aria-hidden="true" />
                            {form.processing ? 'Reading the workbook…' : 'Stage a dry run'}
                        </PrimaryButton>
                    </div>
                </form>
            </div>

            {pending && (
                <div className="card mb-5">
                    <div className="card-body">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    {pending.reference} · {pending.source_name}
                                </h2>
                                <p className="text-sm text-[var(--color-text-secondary)]">
                                    {pending.rows_total} row(s) staged · {pending.controls_added} function(s) new ·{' '}
                                    {pending.controls_changed} changed · {pending.scripts_versioned} checklist(s) would be
                                    drafted as a new version
                                </p>
                            </div>
                            <StatusBadge status={pending.status} />
                        </div>

                        {blocked && (
                            <div className="mt-4 flex gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-800">
                                <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
                                <p>
                                    {pending.rows_unresolved} row(s) carry a frequency this system does not recognise. Add
                                    an alias for each one before committing — an unrecognised frequency is never assumed.
                                </p>
                            </div>
                        )}

                        <h3 className="mt-5 text-sm font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            What would change, by unit
                        </h3>
                        <div className="mt-2">
                            <DataTable
                                columns={[
                                    { field: 'unit', label: 'Unit' },
                                    { field: 'sheet', label: 'Sheet', width: '12rem' },
                                    { field: 'functions', label: 'Functions', width: '7rem' },
                                    { field: 'lines', label: 'Lines', width: '6rem' },
                                    { field: 'added', label: 'Added', width: '6rem' },
                                    { field: 'changed', label: 'Changed', width: '7rem' },
                                    { field: 'unchanged', label: 'Unchanged', width: '8rem' },
                                    {
                                        field: 'unresolved',
                                        label: 'Unresolved',
                                        width: '8rem',
                                        render: (row) => (
                                            <span className={row.unresolved > 0 ? 'font-semibold text-red-600' : ''}>
                                                {row.unresolved}
                                            </span>
                                        ),
                                    },
                                ]}
                                data={pending.diff ?? []}
                                emptyMessage="Nothing to change — this workbook matches the register exactly."
                            />
                        </div>

                        {blocked && (
                            <>
                                <h3 className="mt-5 text-sm font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                    Rows blocking the commit
                                </h3>
                                <div className="mt-2">
                                    <DataTable
                                        columns={[
                                            { field: 'source_ref', label: 'Cell', width: '7rem' },
                                            { field: 'unit', label: 'Unit' },
                                            { field: 'function', label: 'Function' },
                                            { field: 'frequency_raw', label: 'Frequency written', width: '14rem' },
                                        ]}
                                        data={pending.unresolved_rows ?? []}
                                    />
                                </div>
                            </>
                        )}

                        <div className="mt-5 flex justify-end gap-2">
                            <SecondaryButton type="button" onClick={discard}>Discard</SecondaryButton>
                            <PrimaryButton type="button" onClick={commit} disabled={!pending.is_committable}>
                                Commit the import
                            </PrimaryButton>
                        </div>
                    </div>
                </div>
            )}

            <div className="card">
                <div className="card-body pb-0">
                    <h2 className="text-lg font-semibold">Import history</h2>
                </div>
                <DataTable
                    columns={[
                        { field: 'reference', label: 'Reference', width: '9rem' },
                        { field: 'source_name', label: 'Workbook' },
                        { field: 'status', label: 'Status', width: '9rem', render: (row) => <StatusBadge status={row.status} /> },
                        { field: 'rows_total', label: 'Rows', width: '6rem' },
                        { field: 'controls_added', label: 'Added', width: '6rem' },
                        { field: 'controls_changed', label: 'Changed', width: '7rem' },
                        { field: 'created_by', label: 'Run by', width: '12rem' },
                        { field: 'created_at', label: 'When', width: '12rem' },
                    ]}
                    data={imports}
                    emptyMessage="No workbook has been imported yet."
                    onRowClick={(row) =>
                        router.get(route('control-functions.import.index'), { pending: row.id }, { preserveScroll: true })
                    }
                />
            </div>
        </AuthenticatedLayout>
    );
}
