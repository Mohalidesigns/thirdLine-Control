import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Prompt version history with diffs (§C.8).
 *
 * Versions are never edited — publishing writes a new row, because
 * ai_interactions references the version that ran and rewriting one would
 * make an audited interaction unreconstructible (R3). So this page reads as
 * a history: what changed, when, who approved it, and how many calls ran on
 * it.
 */

function Diff({ diff }) {
    const fields = Object.keys(diff ?? {});

    if (!fields.length) {
        return <p className="text-xs italic text-gray-400">No change from the previous version.</p>;
    }

    return (
        <div className="space-y-2">
            {fields.map((field) => (
                <div key={field}>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        {field.replace(/_/g, ' ')}
                    </p>
                    <div className="mt-1 grid gap-1.5 sm:grid-cols-2">
                        <pre className="overflow-x-auto whitespace-pre-wrap rounded border border-red-100 bg-red-50/50 p-2 text-[11px] leading-relaxed text-red-900">
                            {String(
                                typeof diff[field].from === 'object'
                                    ? JSON.stringify(diff[field].from, null, 2)
                                    : (diff[field].from ?? '—'),
                            )}
                        </pre>
                        <pre className="overflow-x-auto whitespace-pre-wrap rounded border border-emerald-100 bg-emerald-50/50 p-2 text-[11px] leading-relaxed text-emerald-900">
                            {String(
                                typeof diff[field].to === 'object'
                                    ? JSON.stringify(diff[field].to, null, 2)
                                    : (diff[field].to ?? '—'),
                            )}
                        </pre>
                    </div>
                </div>
            ))}
        </div>
    );
}

function VersionCard({ version, capabilityKey }) {
    const [open, setOpen] = useState(false);

    return (
        <div className={`card ${version.is_active ? 'border-l-4 border-l-[var(--color-success)]' : ''}`}>
            <div className="card-header flex-wrap gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-sm font-semibold">v{version.version}</span>
                    {version.is_active && (
                        <span className="badge badge-status-active">Live</span>
                    )}
                    {version.is_shipped && (
                        <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600">
                            Shipped library
                        </span>
                    )}
                    <span className="text-xs text-[var(--color-text-secondary)]">
                        {version.usage_count.toLocaleString()} call(s) ran on this version
                    </span>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setOpen((v) => !v)}
                        className="text-xs font-semibold text-[var(--color-primary)] hover:underline"
                    >
                        {open ? 'Hide' : 'View'}
                    </button>
                    {!version.is_active && !version.is_shipped && (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(route('admin.ai.prompts.activate', version.id), {}, { preserveScroll: true })
                            }
                            className="btn-primary px-3 py-1 text-xs"
                        >
                            Make live
                        </button>
                    )}
                </div>
            </div>

            <div className="card-body space-y-3">
                <p className="text-sm text-[var(--color-text-primary)]">
                    {version.changelog || <span className="italic text-gray-400">No changelog recorded.</span>}
                </p>
                <p className="text-[11px] text-gray-400">
                    {version.created_by ? `Written by ${version.created_by}` : 'Shipped with the product'}
                    {version.approved_by && ` · activated by ${version.approved_by}`}
                    {version.model_hint && ` · model hint ${version.model_hint}`}
                </p>

                <Diff diff={version.diff} />

                {open && (
                    <div className="space-y-3 border-t border-gray-100 pt-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                System prompt
                            </p>
                            <pre className="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-[11px] leading-relaxed">
                                {version.system_prompt}
                            </pre>
                        </div>
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                User template
                            </p>
                            <pre className="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-[11px] leading-relaxed">
                                {version.user_template}
                            </pre>
                        </div>
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                Output schema — the contract every reply is validated against
                            </p>
                            <pre className="mt-1 max-h-64 overflow-auto rounded bg-gray-50 p-3 text-[11px] leading-relaxed">
                                {JSON.stringify(version.output_schema, null, 2)}
                            </pre>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function AiPrompts({ capabilityKey, capabilities, versions }) {
    const latest = versions[0];
    const [drafting, setDrafting] = useState(false);

    const form = useForm({
        key: capabilityKey,
        name: latest?.name ?? '',
        system_prompt: latest?.system_prompt ?? '',
        user_template: latest?.user_template ?? '',
        model_hint: latest?.model_hint ?? '',
        max_tokens: latest?.max_tokens ?? 8192,
        changelog: '',
    });

    return (
        <AuthenticatedLayout header="AI Prompts">
            <Head title="AI Prompt Registry" />

            <PageHeader
                title="Prompt registry"
                subtitle="Versions are never edited in place — publishing writes a new one, so every past interaction stays reconstructible."
                breadcrumbs={[{ label: 'AI Governance', href: route('admin.ai.index') }, { label: 'Prompts' }]}
                actions={
                    <PrimaryButton onClick={() => setDrafting((v) => !v)}>
                        {drafting ? 'Cancel' : 'New version'}
                    </PrimaryButton>
                }
            />

            <div className="mb-6 flex flex-wrap gap-2">
                {capabilities.map((capability) => (
                    <Link
                        key={capability.key}
                        href={route('admin.ai.prompts', { key: capability.key })}
                        className={`rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors duration-200 ${
                            capability.key === capabilityKey
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white'
                                : 'border-gray-200 bg-white text-[var(--color-text-secondary)] hover:border-gray-300'
                        }`}
                    >
                        {capability.label}
                    </Link>
                ))}
            </div>

            {drafting && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.ai.prompts.store'), {
                            preserveScroll: true,
                            onSuccess: () => setDrafting(false),
                        });
                    }}
                    className="card mb-6"
                >
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">
                            Publish version {(latest?.version ?? 0) + 1} of {capabilityKey}
                        </h2>
                    </div>
                    <div className="card-body space-y-4">
                        <div>
                            <label className="form-label" htmlFor="changelog">
                                What changed <span className="text-red-500">*</span>
                            </label>
                            <input
                                id="changelog"
                                className="form-input"
                                value={form.data.changelog}
                                onChange={(e) => form.setData('changelog', e.target.value)}
                                placeholder="Tightened the root-cause instruction; the previous version restated the failure."
                            />
                            {form.errors.changelog && (
                                <p className="mt-1 text-xs text-[var(--color-error)]">{form.errors.changelog}</p>
                            )}
                        </div>
                        <div>
                            <label className="form-label" htmlFor="system_prompt">
                                System prompt
                            </label>
                            <textarea
                                id="system_prompt"
                                className="form-input font-mono text-xs"
                                rows={12}
                                value={form.data.system_prompt}
                                onChange={(e) => form.setData('system_prompt', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="form-label" htmlFor="user_template">
                                User template — <span className="font-mono">{'{{variable}}'}</span> placeholders are
                                substituted after redaction
                            </label>
                            <textarea
                                id="user_template"
                                className="form-input font-mono text-xs"
                                rows={10}
                                value={form.data.user_template}
                                onChange={(e) => form.setData('user_template', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="form-label" htmlFor="model_hint">
                                    Model hint (optional)
                                </label>
                                <input
                                    id="model_hint"
                                    className="form-input"
                                    value={form.data.model_hint ?? ''}
                                    onChange={(e) => form.setData('model_hint', e.target.value)}
                                />
                            </div>
                            <div>
                                <label className="form-label" htmlFor="max_tokens">
                                    Max output tokens
                                </label>
                                <input
                                    id="max_tokens"
                                    type="number"
                                    className="form-input"
                                    value={form.data.max_tokens}
                                    onChange={(e) => form.setData('max_tokens', Number(e.target.value))}
                                />
                            </div>
                        </div>
                        <p className="text-xs text-[var(--color-text-secondary)]">
                            The new version is saved inactive. Nothing changes for users until you make it live.
                        </p>
                        <PrimaryButton disabled={form.processing}>Save version</PrimaryButton>
                    </div>
                </form>
            )}

            <div className="space-y-4">
                {versions.map((version) => (
                    <VersionCard key={version.id} version={version} capabilityKey={capabilityKey} />
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
