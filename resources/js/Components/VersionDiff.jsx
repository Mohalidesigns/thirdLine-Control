import { useEffect, useState } from 'react';
import axios from 'axios';

/**
 * Side-by-side field diff for any HasVersions object (9.8).
 * Props: alias ('document', 'test-script', …) and the record id.
 */
export default function VersionDiff({ alias, id }) {
    const [data, setData] = useState(null);
    const [from, setFrom] = useState(null);
    const [to, setTo] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        const params = {};
        if (from) params.from = from;
        if (to) params.to = to;

        axios
            .get(route('versions.diff', [alias, id]), { params })
            .then(({ data }) => { setData(data); setError(null); })
            .catch(() => setError('Version history could not be loaded.'));
    }, [alias, id, from, to]);

    if (error) return <p className="text-sm text-[var(--color-text-secondary)]">{error}</p>;
    if (!data) return <p className="text-sm text-[var(--color-text-secondary)]">Loading version history…</p>;
    if (data.versions.length < 2) {
        return <p className="text-sm text-[var(--color-text-secondary)]">Only one version exists — nothing to compare yet.</p>;
    }

    const fields = Object.entries(data.diff);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="text-[var(--color-text-secondary)]">Compare</span>
                <select className="form-select !w-auto !py-1 text-xs" value={from ?? data.from} onChange={(e) => setFrom(Number(e.target.value))}>
                    {data.versions.map((v) => <option key={v.version_no} value={v.version_no}>v{v.version_no}</option>)}
                </select>
                <span className="text-[var(--color-text-secondary)]">with</span>
                <select className="form-select !w-auto !py-1 text-xs" value={to ?? data.to} onChange={(e) => setTo(Number(e.target.value))}>
                    {data.versions.map((v) => <option key={v.version_no} value={v.version_no}>v{v.version_no}</option>)}
                </select>
            </div>

            {fields.length === 0 ? (
                <p className="text-sm text-[var(--color-text-secondary)]">No differences between these versions.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr><th>Field</th><th>v{data.from}</th><th>v{data.to}</th></tr>
                        </thead>
                        <tbody>
                            {fields.map(([field, change]) => (
                                <tr key={field}>
                                    <td className="font-mono text-xs">{field}</td>
                                    <td className="bg-red-50/60 text-sm text-red-800">{renderValue(change.from)}</td>
                                    <td className="bg-green-50/60 text-sm text-green-800">{renderValue(change.to)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="text-xs text-[var(--color-text-secondary)]">
                {data.versions.map((v) => (
                    <p key={v.version_no}>
                        v{v.version_no} — {v.author ?? 'system'}
                        {v.change_reason ? ` · ${v.change_reason}` : ''}
                    </p>
                ))}
            </div>
        </div>
    );
}

function renderValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'object') return JSON.stringify(value);
    if (value === true) return 'Yes';
    if (value === false) return 'No';
    return String(value);
}
