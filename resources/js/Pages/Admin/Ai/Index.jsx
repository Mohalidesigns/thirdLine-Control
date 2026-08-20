import PageHeader from '@/Components/PageHeader';
import ProgressBar from '@/Components/ProgressBar';
import StatCard from '@/Components/StatCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Admin → AI (§C.8).
 *
 * Two audiences at once: the administrator running the module, and the
 * compliance officer who has to evidence to their own regulator that the AI
 * in their control environment is governed. The page is laid out for the
 * second one — oversight, spend, and the honest quality metric first;
 * settings after.
 */

function Toggle({ checked, onChange, disabled = false }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${
                checked ? 'bg-[var(--color-success)]' : 'bg-gray-300'
            }`}
        >
            <span
                className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 ${
                    checked ? 'translate-x-6' : 'translate-x-1'
                }`}
            />
        </button>
    );
}

function money(minor, currency) {
    return new Intl.NumberFormat('en-NG', { style: 'currency', currency: currency || 'USD' }).format(
        (minor ?? 0) / 100,
    );
}

function CapabilityRow({ capability, roles, quality, canManage }) {
    const [expanded, setExpanded] = useState(false);
    const stats = quality.find((q) => q.capability === capability.key);

    const save = (changes) => {
        router.put(
            route('admin.ai.capabilities.update'),
            {
                capability_key: capability.key,
                is_enabled: capability.is_enabled,
                model: capability.model_is_override ? capability.model : null,
                monthly_token_budget: capability.monthly_token_budget,
                requires_approval_role_id: capability.requires_approval_role_id,
                min_confidence_to_surface: capability.min_confidence_to_surface,
                ...changes,
            },
            { preserveScroll: true },
        );
    };

    return (
        <tr className="align-top">
            <td>
                <button
                    type="button"
                    onClick={() => setExpanded((v) => !v)}
                    className="text-left font-medium text-[var(--color-text-primary)] hover:underline"
                >
                    {capability.label}
                </button>
                <p className="mt-0.5 font-mono text-[11px] text-gray-400">{capability.key}</p>
                {expanded && (
                    <div className="mt-2 max-w-md space-y-2 text-xs text-[var(--color-text-secondary)]">
                        <p>{capability.description}</p>
                        <p>
                            Requires the <span className="font-mono">{capability.permission}</span> permission —
                            this assistant never widens what a user can already do.
                        </p>
                        {canManage && (
                            <div>
                                <label className="form-label text-xs" htmlFor={`role-${capability.key}`}>
                                    Restrict who may accept its suggestions
                                </label>
                                <select
                                    id={`role-${capability.key}`}
                                    className="form-select text-xs"
                                    value={capability.requires_approval_role_id ?? ''}
                                    onChange={(e) =>
                                        save({ requires_approval_role_id: e.target.value || null })
                                    }
                                >
                                    <option value="">Anyone holding the permission above</option>
                                    {roles.map((role) => (
                                        <option key={role.id} value={role.id}>
                                            {role.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                )}
            </td>
            <td className="whitespace-nowrap">
                <span className="font-mono text-xs">{capability.model}</span>
                <p className="text-[11px] text-gray-400">
                    {capability.model_is_override ? 'tenant override' : `${capability.tier} tier`}
                </p>
            </td>
            <td className="whitespace-nowrap text-center">
                {capability.active_prompt_version ? `v${capability.active_prompt_version}` : '—'}
            </td>
            <td className="whitespace-nowrap text-right">{capability.calls_30d.toLocaleString()}</td>
            <td className="whitespace-nowrap text-right">
                {stats?.acceptance_rate != null ? (
                    <>
                        <span className="font-semibold">{stats.acceptance_rate}%</span>
                        {stats.override_rate > 0 && (
                            <p className="text-[11px] text-gray-400">{stats.override_rate}% edited first</p>
                        )}
                    </>
                ) : (
                    <span className="text-gray-400">—</span>
                )}
            </td>
            <td className="text-center">
                <Toggle
                    checked={capability.is_enabled}
                    disabled={!canManage}
                    onChange={(next) => save({ is_enabled: next })}
                />
            </td>
        </tr>
    );
}

export default function AiIndex({ capabilities, budget, usage, quality, rejections, index, models, roles, can }) {
    const budgetForm = useForm({
        token_budget: budget.token_budget,
        cost_budget_minor: budget.cost_budget_minor,
        hard_stop: budget.hard_stop,
    });

    const totalCalls = capabilities.reduce((sum, c) => sum + c.calls_30d, 0);
    const decided = quality.reduce((sum, q) => sum + q.accepted + q.edited + q.rejected, 0);
    const kept = quality.reduce((sum, q) => sum + q.accepted + q.edited, 0);

    return (
        <AuthenticatedLayout header="AI Governance">
            <Head title="AI Governance" />

            <PageHeader
                title="AI Governance"
                subtitle="Human oversight, model registry, spend and the honest quality metric — for running the module, and for evidencing it."
                actions={
                    can.viewLog && (
                        <Link href={route('admin.ai.log')} className="btn-secondary">
                            Activity log
                        </Link>
                    )
                }
            />

            {!models.configured && (
                <div className="card mb-6 border-l-4 border-l-[var(--color-warning)]">
                    <div className="card-body">
                        <p className="text-sm font-semibold text-[var(--color-text-primary)]">
                            No Ollama endpoint is configured for this installation.
                        </p>
                        <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
                            The AI layer is dormant: every capability reports itself unavailable rather than
                            failing mid-request. Set <span className="font-mono">OLLAMA_BASE_URL</span> in the
                            environment to activate it.
                        </p>
                    </div>
                </div>
            )}

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard title="Calls (30 days)" value={totalCalls.toLocaleString()} color="primary" />
                <StatCard
                    title="Kept by reviewers"
                    value={decided > 0 ? `${Math.round((kept / decided) * 100)}%` : '—'}
                    subtitle={`${decided.toLocaleString()} decided`}
                    color={decided > 0 && kept / decided < 0.5 ? 'warning' : 'success'}
                />
                <StatCard
                    title="Spend this period"
                    value={money(budget.cost_used_minor, budget.currency)}
                    subtitle={`of ${money(budget.cost_budget_minor, budget.currency)} · ${budget.period_label}`}
                    color={budget.exhausted ? 'danger' : 'info'}
                />
                <StatCard
                    title="Indexed records"
                    value={index.total_chunks.toLocaleString()}
                    subtitle={index.stale_chunks > 0 ? `${index.stale_chunks.toLocaleString()} awaiting re-index` : 'up to date'}
                    color={index.stale_chunks > 0 ? 'warning' : 'success'}
                />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="card lg:col-span-2">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Capabilities</h2>
                        <span className="text-xs text-[var(--color-text-secondary)]">
                            A capability with no row is off. Nothing runs until it is switched on here.
                        </span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Capability</th>
                                    <th>Model</th>
                                    <th className="text-center">Prompt</th>
                                    <th className="text-right">Calls 30d</th>
                                    <th className="text-right">Kept</th>
                                    <th className="text-center">Enabled</th>
                                </tr>
                            </thead>
                            <tbody>
                                {capabilities.map((capability) => (
                                    <CapabilityRow
                                        key={capability.key}
                                        capability={capability}
                                        roles={roles}
                                        quality={quality}
                                        canManage={can.manage}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {can.manage && (
                        <div className="border-t border-gray-100 px-6 py-3">
                            <Link href={route('admin.ai.prompts')} className="text-xs font-semibold text-[var(--color-primary)] hover:underline">
                                Prompt version history and diffs →
                            </Link>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold">Budget · {budget.period_label}</h2>
                        </div>
                        <div className="card-body space-y-4">
                            <div>
                                <div className="mb-1 flex justify-between text-xs">
                                    <span className="text-[var(--color-text-secondary)]">Tokens</span>
                                    <span className="font-medium">{budget.token_percent}%</span>
                                </div>
                                <ProgressBar value={Math.min(100, budget.token_percent)} />
                                <p className="mt-1 text-[11px] text-gray-400">
                                    {budget.tokens_used.toLocaleString()} of {budget.token_budget.toLocaleString()}
                                </p>
                            </div>

                            <div>
                                <div className="mb-1 flex justify-between text-xs">
                                    <span className="text-[var(--color-text-secondary)]">Cost</span>
                                    <span className="font-medium">{budget.cost_percent}%</span>
                                </div>
                                <ProgressBar value={Math.min(100, budget.cost_percent)} />
                                <p className="mt-1 text-[11px] text-gray-400">
                                    {money(budget.cost_used_minor, budget.currency)} of{' '}
                                    {money(budget.cost_budget_minor, budget.currency)}
                                </p>
                            </div>

                            {can.manage && (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        budgetForm.put(route('admin.ai.budget.update'), { preserveScroll: true });
                                    }}
                                    className="space-y-3 border-t border-gray-100 pt-4"
                                >
                                    <div>
                                        <label className="form-label text-xs" htmlFor="token_budget">
                                            Token ceiling
                                        </label>
                                        <input
                                            id="token_budget"
                                            type="number"
                                            className="form-input text-sm"
                                            value={budgetForm.data.token_budget}
                                            onChange={(e) => budgetForm.setData('token_budget', Number(e.target.value))}
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label text-xs" htmlFor="cost_budget_minor">
                                            Cost ceiling ({budget.currency} minor units)
                                        </label>
                                        <input
                                            id="cost_budget_minor"
                                            type="number"
                                            className="form-input text-sm"
                                            value={budgetForm.data.cost_budget_minor}
                                            onChange={(e) =>
                                                budgetForm.setData('cost_budget_minor', Number(e.target.value))
                                            }
                                        />
                                    </div>
                                    <label className="flex items-start gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            className="mt-0.5"
                                            checked={budgetForm.data.hard_stop}
                                            onChange={(e) => budgetForm.setData('hard_stop', e.target.checked)}
                                        />
                                        <span>
                                            <span className="font-medium">Hard stop at the ceiling.</span> Refuses the
                                            call rather than warning — the only honest behaviour for a spend cap.
                                        </span>
                                    </label>
                                    <button type="submit" className="btn-primary w-full text-sm" disabled={budgetForm.processing}>
                                        Save budget
                                    </button>
                                </form>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h2 className="text-sm font-semibold">Why drafts are rejected</h2>
                        </div>
                        <div className="card-body">
                            {rejections.length === 0 ? (
                                <p className="text-xs text-[var(--color-text-secondary)]">
                                    No rejections recorded yet.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {rejections.map((row) => (
                                        <li key={row.category} className="flex justify-between text-xs">
                                            <span className="capitalize text-[var(--color-text-secondary)]">
                                                {row.category}
                                            </span>
                                            <span className="font-semibold">{row.count}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Usage, last 30 days</h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th className="text-right">Calls</th>
                                <th className="text-right">Tokens</th>
                                <th className="text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            {usage.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="py-6 text-center text-sm text-gray-400">
                                        No AI activity in the last 30 days.
                                    </td>
                                </tr>
                            ) : (
                                usage.map((row) => (
                                    <tr key={row.day}>
                                        <td className="font-mono text-xs">{row.day}</td>
                                        <td className="text-right">{row.calls.toLocaleString()}</td>
                                        <td className="text-right">{row.tokens.toLocaleString()}</td>
                                        <td className="text-right">{money(row.cost_minor, budget.currency)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
