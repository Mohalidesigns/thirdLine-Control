import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

const LABELS = {
    controls: 'Controls',
    risks: 'Risks',
    obligations: 'Regulatory obligations',
    'org-units': 'Organisation units',
    users: 'Users',
};

export default function Import({ resources = [] }) {
    const [active, setActive] = useState(resources[0]?.key ?? 'controls');
    const [file, setFile] = useState(null);
    const [report, setReport] = useState(null);
    const [busy, setBusy] = useState(false);

    const resource = resources.find((r) => r.key === active);

    const runDryRun = async () => {
        if (!file) return;
        setBusy(true);
        setReport(null);
        try {
            const form = new FormData();
            form.append('file', file);
            const { data } = await axios.post(route('admin.import.dry-run', active), form);
            setReport(data);
        } catch (e) {
            setReport({ rows: 0, valid: 0, errors: { 0: [e.response?.data?.message ?? 'The file could not be read.'] }, preview: [] });
        } finally {
            setBusy(false);
        }
    };

    const runImport = () => {
        if (!file || !report || Object.keys(report.errors).length > 0) return;
        setBusy(true);
        router.post(route('admin.import.store', active), { file }, {
            forceFormData: true,
            onFinish: () => { setBusy(false); setFile(null); setReport(null); },
        });
    };

    return (
        <AuthenticatedLayout header="Bulk Import">
            <Head title="Bulk Import" />
            <PageHeader
                title="Bulk Excel Import"
                subtitle="Download a template, fill it, dry-run to validate — nothing is written until every row passes"
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {resources.map((r) => (
                    <button
                        key={r.key}
                        type="button"
                        onClick={() => { setActive(r.key); setFile(null); setReport(null); }}
                        className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors duration-200 ${
                            active === r.key
                                ? 'bg-[var(--color-primary)] text-white'
                                : 'bg-white text-[var(--color-text-secondary)] ring-1 ring-gray-200 hover:bg-gray-50'
                        }`}
                    >
                        {LABELS[r.key] ?? r.key}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="card lg:col-span-2">
                    <div className="card-header"><h3 className="text-sm font-semibold">1 · Template → 2 · Dry-run → 3 · Import</h3></div>
                    <div className="card-body space-y-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <a href={route('admin.import.template', active)} className="btn-secondary">
                                Download {LABELS[active]?.toLowerCase()} template
                            </a>
                            <input
                                type="file"
                                accept=".xlsx,.xls"
                                className="text-sm"
                                onChange={(e) => { setFile(e.target.files[0] ?? null); setReport(null); }}
                            />
                        </div>

                        <div className="flex gap-2">
                            <SecondaryButton disabled={!file || busy} onClick={runDryRun}>
                                {busy ? 'Checking…' : 'Dry-run validation'}
                            </SecondaryButton>
                            <PrimaryButton
                                disabled={!report || Object.keys(report?.errors ?? { x: 1 }).length > 0 || busy}
                                onClick={runImport}
                            >
                                Import {report ? `${report.valid} rows` : ''}
                            </PrimaryButton>
                        </div>

                        {report && (
                            <div className={`rounded-xl border p-4 ${Object.keys(report.errors).length ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50'}`}>
                                <p className="text-sm font-semibold">
                                    {report.rows} rows read · {report.valid} valid · {Object.keys(report.errors).length} with errors
                                </p>
                                {Object.keys(report.errors).length > 0 ? (
                                    <ul className="mt-2 max-h-64 space-y-1 overflow-y-auto text-sm text-red-700">
                                        {Object.entries(report.errors).map(([row, messages]) => (
                                            <li key={row}>
                                                <strong>Row {row}:</strong> {messages.join(' ')}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="mt-1 text-sm text-green-700">
                                        Every row passed. The import is all-or-nothing: a failure mid-file rolls everything back.
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                <div className="card h-fit">
                    <div className="card-header"><h3 className="text-sm font-semibold">Template columns</h3></div>
                    <div className="card-body">
                        <ul className="space-y-1 text-sm">
                            {(resource?.columns ?? []).map((c) => (
                                <li key={c.key} className="flex items-center justify-between">
                                    <span>{c.label}</span>
                                    {c.required && <span className="badge badge-high">required</span>}
                                </li>
                            ))}
                        </ul>
                        {active === 'obligations' && (
                            <p className="mt-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
                                Imported obligations arrive <strong>unverified</strong> and are excluded from regulatory submissions
                                until a human verifies them against the regulator's primary document.
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
