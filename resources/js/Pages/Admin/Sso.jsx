import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const EMPTY = {
    protocol: 'oidc',
    display_name: '',
    entity_id: '',
    sso_url: '',
    slo_url: '',
    x509_cert: '',
    oidc_issuer: '',
    oidc_client_id: '',
    oidc_client_secret: '',
    oidc_discovery_url: '',
    attribute_map: { email: 'email', name: 'name', groups: 'groups', role_map: {} },
    jit_provisioning: false,
    default_role_id: '',
    allowed_email_domains: [],
    is_enabled: true,
};

function StatusBadgeFor({ config }) {
    if (config.is_active && !config.has_pending_changes) {
        return <span className="badge badge-status-active">Active</span>;
    }
    if (config.has_pending_changes) {
        return <span className="badge badge-status-pending">Changes pending approval</span>;
    }
    if (!config.approved_at) {
        return <span className="badge badge-status-pending">Awaiting approval</span>;
    }
    return <span className="badge badge-status-draft">Disabled</span>;
}

export default function Sso({ configurations, roles }) {
    const [editing, setEditing] = useState(null); // null | 'new' | config object
    const [approving, setApproving] = useState(null);
    const form = useForm(EMPTY);

    const openNew = () => {
        form.setDefaults(EMPTY);
        form.reset();
        form.clearErrors();
        setEditing('new');
    };

    const openEdit = (config) => {
        form.setData({
            ...EMPTY,
            ...Object.fromEntries(Object.entries(config).filter(([k]) => k in EMPTY && config[k] !== null)),
            oidc_client_secret: '',
            default_role_id: config.default_role_id ?? '',
        });
        form.clearErrors();
        setEditing(config);
    };

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') {
            form.post(route('admin.sso.store'), options);
        } else {
            form.put(route('admin.sso.update', editing.id), options);
        }
    };

    const isSaml = form.data.protocol === 'saml2';

    return (
        <AuthenticatedLayout header="Single Sign-On">
            <Head title="Single Sign-On" />

            <PageHeader
                title="Single sign-on"
                subtitle="SAML 2.0 and OpenID Connect identity providers. Every change requires approval by a second System Administrator before it takes effect."
                actions={<PrimaryButton onClick={openNew}>Add identity provider</PrimaryButton>}
            />

            {configurations.length === 0 ? (
                <EmptyState
                    title="No identity providers configured"
                    description="Add your Entra ID (Azure AD), Okta, or any SAML 2.0 / OIDC provider to enable single sign-on."
                />
            ) : (
                <div className="space-y-4">
                    {configurations.map((config) => (
                        <div key={config.id} className="card">
                            <div className="card-header">
                                <div className="flex items-center gap-3">
                                    <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">
                                        {config.display_name}
                                    </h3>
                                    <span className="badge badge-status-draft uppercase">{config.protocol}</span>
                                    <StatusBadgeFor config={config} />
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <SecondaryButton onClick={() => router.post(route('admin.sso.test', config.id), {}, { preserveScroll: true })}>
                                        Test connection
                                    </SecondaryButton>
                                    <SecondaryButton onClick={() => openEdit(config)}>Edit</SecondaryButton>
                                    {(config.has_pending_changes || !config.approved_at) && config.can_approve && (
                                        <>
                                            <PrimaryButton onClick={() => setApproving(config)}>Approve</PrimaryButton>
                                            <SecondaryButton
                                                onClick={() => router.post(route('admin.sso.reject', config.id), {}, { preserveScroll: true })}
                                            >
                                                Reject
                                            </SecondaryButton>
                                        </>
                                    )}
                                </div>
                            </div>
                            <div className="card-body grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <p className="text-xs font-semibold uppercase text-[var(--color-text-secondary)]">Created by</p>
                                    <p>{config.creator ?? '—'}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold uppercase text-[var(--color-text-secondary)]">Approved by</p>
                                    <p>{config.approver ?? 'Awaiting a second administrator'}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold uppercase text-[var(--color-text-secondary)]">JIT provisioning</p>
                                    <p>{config.jit_provisioning ? 'Enabled' : 'Disabled'}</p>
                                </div>
                                {config.has_pending_changes && (
                                    <div className="sm:col-span-2 lg:col-span-3">
                                        <p className="text-xs font-semibold uppercase text-[var(--color-text-secondary)]">
                                            Pending changes by {config.proposer}
                                        </p>
                                        <p className="font-mono text-xs text-[var(--color-warning)]">
                                            {Object.keys(config.pending_changes ?? {}).join(', ')}
                                        </p>
                                    </div>
                                )}
                                {config.metadata_url && (
                                    <div className="sm:col-span-2 lg:col-span-3">
                                        <p className="text-xs font-semibold uppercase text-[var(--color-text-secondary)]">SP metadata for your IdP</p>
                                        <a href={config.metadata_url} className="break-all font-mono text-xs text-[var(--color-primary)] hover:underline">
                                            {config.metadata_url}
                                        </a>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="2xl">
                <form onSubmit={submit} className="p-6">
                    <h2 className="mb-4 text-lg font-semibold text-[var(--color-text-primary)]">
                        {editing === 'new' ? 'Add identity provider' : 'Propose changes'}
                    </h2>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Protocol" required />
                            <SelectInput
                                value={form.data.protocol}
                                onChange={(e) => form.setData('protocol', e.target.value)}
                            >
                                <option value="oidc">OpenID Connect (Entra ID, Okta…)</option>
                                <option value="saml2">SAML 2.0</option>
                            </SelectInput>
                            <InputError message={form.errors.protocol} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Display name" required />
                            <TextInput
                                value={form.data.display_name}
                                placeholder="e.g. Sign in with Microsoft"
                                onChange={(e) => form.setData('display_name', e.target.value)}
                            />
                            <InputError message={form.errors.display_name} className="mt-1" />
                        </div>

                        {isSaml ? (
                            <>
                                <div>
                                    <InputLabel value="IdP entity ID" required />
                                    <TextInput value={form.data.entity_id} onChange={(e) => form.setData('entity_id', e.target.value)} />
                                    <InputError message={form.errors.entity_id} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="IdP SSO URL" required />
                                    <TextInput value={form.data.sso_url} onChange={(e) => form.setData('sso_url', e.target.value)} />
                                    <InputError message={form.errors.sso_url} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="IdP SLO URL" />
                                    <TextInput value={form.data.slo_url} onChange={(e) => form.setData('slo_url', e.target.value)} />
                                    <InputError message={form.errors.slo_url} className="mt-1" />
                                </div>
                                <div className="sm:col-span-2">
                                    <InputLabel value="IdP X.509 certificate (PEM)" required />
                                    <TextArea
                                        rows={4}
                                        value={form.data.x509_cert}
                                        onChange={(e) => form.setData('x509_cert', e.target.value)}
                                    />
                                    <InputError message={form.errors.x509_cert} className="mt-1" />
                                </div>
                            </>
                        ) : (
                            <>
                                <div>
                                    <InputLabel value="Issuer URL" required />
                                    <TextInput
                                        value={form.data.oidc_issuer}
                                        placeholder="https://login.microsoftonline.com/{tenant}/v2.0"
                                        onChange={(e) => form.setData('oidc_issuer', e.target.value)}
                                    />
                                    <InputError message={form.errors.oidc_issuer} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Client ID" required />
                                    <TextInput value={form.data.oidc_client_id} onChange={(e) => form.setData('oidc_client_id', e.target.value)} />
                                    <InputError message={form.errors.oidc_client_id} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value={editing === 'new' ? 'Client secret' : 'Client secret (leave blank to keep)'} required={editing === 'new'} />
                                    <TextInput
                                        type="password"
                                        value={form.data.oidc_client_secret}
                                        onChange={(e) => form.setData('oidc_client_secret', e.target.value)}
                                    />
                                    <InputError message={form.errors.oidc_client_secret} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Discovery URL (optional)" />
                                    <TextInput
                                        value={form.data.oidc_discovery_url}
                                        placeholder="…/.well-known/openid-configuration"
                                        onChange={(e) => form.setData('oidc_discovery_url', e.target.value)}
                                    />
                                    <InputError message={form.errors.oidc_discovery_url} className="mt-1" />
                                </div>
                            </>
                        )}

                        <div>
                            <InputLabel value="Default role for new users" />
                            <SelectInput
                                value={form.data.default_role_id}
                                onChange={(e) => form.setData('default_role_id', e.target.value ? Number(e.target.value) : '')}
                            >
                                <option value="">Executive Viewer (fallback)</option>
                                {roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.name}
                                    </option>
                                ))}
                            </SelectInput>
                            <InputError message={form.errors.default_role_id} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Allowed email domains (comma-separated)" />
                            <TextInput
                                value={(form.data.allowed_email_domains ?? []).join(', ')}
                                placeholder="firstbank.ng, fbnholdings.com"
                                onChange={(e) =>
                                    form.setData(
                                        'allowed_email_domains',
                                        e.target.value.split(',').map((d) => d.trim()).filter(Boolean),
                                    )
                                }
                            />
                            <InputError message={form.errors.allowed_email_domains} className="mt-1" />
                        </div>

                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                checked={form.data.jit_provisioning}
                                onChange={(e) => form.setData('jit_provisioning', e.target.checked)}
                            />
                            <span className="text-sm text-gray-700">Provision unknown users on first sign-in (JIT)</span>
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                checked={form.data.is_enabled}
                                onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            />
                            <span className="text-sm text-gray-700">Enabled (once approved)</span>
                        </label>
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={() => setEditing(null)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>
                            {editing === 'new' ? 'Create (requires approval)' : 'Propose changes'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                show={approving !== null}
                variant="primary"
                title="Approve SSO configuration"
                message={`Approve "${approving?.display_name}"? It takes effect immediately for every user in this organisation.`}
                confirmLabel="Approve"
                onConfirm={() => {
                    router.post(route('admin.sso.approve', approving.id), {}, { preserveScroll: true });
                    setApproving(null);
                }}
                onCancel={() => setApproving(null)}
            />
        </AuthenticatedLayout>
    );
}
