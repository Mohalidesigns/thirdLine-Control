import Checkbox from '@/Components/Checkbox';
import DataTable from '@/Components/DataTable';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/utils';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ attestations, outstanding = [], filters = {}, campaigns = [] }) {
    const columns = [
        { field: 'user', label: 'Attested by', render: (r) => r.user?.name ?? '—' },
        {
            field: 'attestable',
            label: 'Subject',
            render: (r) => (
                <div className="max-w-sm">
                    <p className="font-medium">{r.attestable?.title ?? r.attestable?.name ?? `${r.attestable_type} #${r.attestable_id}`}</p>
                    <p className="text-xs text-gray-400">v{r.version_attested ?? '—'} · {r.method}</p>
                </div>
            ),
        },
        { field: 'campaign', label: 'Campaign', render: (r) => r.campaign?.reference ?? '—' },
        { field: 'attested_at', label: 'Attested', render: (r) => formatDate(r.attested_at) },
        { field: 'ip_address', label: 'IP', render: (r) => <span className="font-mono text-xs">{r.ip_address ?? '—'}</span> },
    ];

    return (
        <AuthenticatedLayout header="Attestations">
            <Head title="Attestations" />
            <PageHeader
                title="Attestations"
                subtitle="Signed policy, code-of-conduct and control attestations — the text signed is snapshotted verbatim"
            />

            {outstanding.map((campaign) => (
                <OutstandingCard key={campaign.id} campaign={campaign} />
            ))}

            {campaigns.length > 0 && (
                <div className="filter-bar mb-4">
                    <div className="filter-bar-inner">
                        <div className="filter-bar-group">
                            <label className="filter-bar-label">Campaign</label>
                            <select
                                className="filter-bar-select"
                                value={filters.campaign_id ?? ''}
                                onChange={(e) => router.get(route('attestations.index'), e.target.value ? { campaign_id: e.target.value } : {}, { preserveState: true })}
                            >
                                <option value="">All campaigns</option>
                                {campaigns.map((c) => <option key={c.id} value={c.id}>{c.reference} — {c.name}</option>)}
                            </select>
                        </div>
                    </div>
                </div>
            )}

            <div className="card">
                <DataTable columns={columns} data={attestations.data} emptyMessage="No attestations recorded yet." />
                <Pagination paginator={attestations} />
            </div>
        </AuthenticatedLayout>
    );
}

function OutstandingCard({ campaign }) {
    const [accepted, setAccepted] = useState(false);
    const [processing, setProcessing] = useState(false);

    const sign = () => {
        setProcessing(true);
        router.post(route('attestations.store', campaign.id), { accepted }, { onFinish: () => setProcessing(false) });
    };

    return (
        <div className="card mb-6 border-l-4 border-l-[var(--color-accent)]">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Attestation required — {campaign.name}</h3>
                <p className="text-xs text-[var(--color-text-secondary)]">
                    {campaign.reference}{campaign.closes_at ? ` · closes ${formatDate(campaign.closes_at)}` : ''}
                </p>
            </div>
            <div className="card-body space-y-4">
                <div className="max-h-64 overflow-y-auto whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-[var(--color-text-primary)]">
                    <p className="mb-2 font-semibold">{campaign.subject.label}</p>
                    {campaign.subject.text ?? 'Refer to the attached document.'}
                </div>
                <label className="flex items-start gap-2 text-sm">
                    <Checkbox checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
                    <span>
                        I confirm that I have read and understood the above, exactly as displayed, and I attest to it.
                        My name, the timestamp, my IP address and this text will be recorded.
                    </span>
                </label>
                <div className="flex justify-end">
                    <PrimaryButton disabled={!accepted || processing} onClick={sign}>Sign attestation</PrimaryButton>
                </div>
            </div>
        </div>
    );
}
