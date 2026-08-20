import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function Checkbox({ checked, onChange, disabled = false }) {
    return (
        <input
            type="checkbox"
            className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)] disabled:opacity-50"
            checked={checked}
            disabled={disabled}
            onChange={onChange}
        />
    );
}

function RoleCard({ role, permissionGroups }) {
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState(role.permissions);
    const [saving, setSaving] = useState(false);

    const dirty =
        selected.length !== role.permissions.length ||
        selected.some((p) => !role.permissions.includes(p));

    const toggle = (permission) =>
        setSelected((current) =>
            current.includes(permission)
                ? current.filter((p) => p !== permission)
                : [...current, permission],
        );

    const save = () => {
        setSaving(true);
        router.put(
            route('admin.roles.update', role.id),
            { permissions: selected },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    const destroy = () => {
        if (confirm(`Delete the role '${role.name}'? This cannot be undone.`)) {
            router.delete(route('admin.roles.destroy', role.id), { preserveScroll: true });
        }
    };

    return (
        <div className="card">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="card-header flex w-full items-center justify-between text-left"
            >
                <div className="flex items-center gap-3">
                    <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">{role.name}</h3>
                    {role.is_locked && <span className="badge badge-status-active">All permissions · locked</span>}
                    {!role.is_seeded && <span className="badge badge-status-draft">Custom</span>}
                </div>
                <div className="flex items-center gap-4 text-xs text-[var(--color-text-secondary)]">
                    <span>{role.users_count} {role.users_count === 1 ? 'user' : 'users'}</span>
                    <span>{role.permissions.length} permissions</span>
                    <svg
                        className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`}
                        fill="none" viewBox="0 0 24 24" strokeWidth={1.7} stroke="currentColor"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </button>

            {open && (
                <div className="card-body space-y-4">
                    {role.is_locked && (
                        <p className="text-xs text-[var(--color-text-secondary)]">
                            The System Administrator role is immutable so an administrator can never
                            edit themselves out of the ability to undo a mistake made on this screen.
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {permissionGroups.map(({ group, permissions }) => (
                            <div key={group}>
                                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                    {group.replace(/-/g, ' ')}
                                </p>
                                <div className="space-y-1">
                                    {permissions.map((permission) => (
                                        <label key={permission} className="flex items-center gap-2">
                                            <Checkbox
                                                checked={role.is_locked || selected.includes(permission)}
                                                disabled={role.is_locked}
                                                onChange={() => toggle(permission)}
                                            />
                                            <span className="text-sm text-gray-700">{permission}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    {!role.is_locked && (
                        <div className="flex items-center justify-between border-t border-[var(--color-border)] pt-4">
                            <div>
                                {!role.is_seeded && role.users_count === 0 && (
                                    <button
                                        type="button"
                                        onClick={destroy}
                                        className="text-sm font-medium text-[var(--color-danger)] hover:underline"
                                    >
                                        Delete role
                                    </button>
                                )}
                            </div>
                            <PrimaryButton disabled={!dirty || saving} onClick={save}>
                                {saving ? 'Saving…' : 'Save permissions'}
                            </PrimaryButton>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function UserRow({ user, roleNames }) {
    const [selected, setSelected] = useState(user.roles);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    const dirty =
        selected.length !== user.roles.length ||
        selected.some((r) => !user.roles.includes(r));

    const toggle = (role) => {
        setSaved(false);
        setSelected((current) =>
            current.includes(role) ? current.filter((r) => r !== role) : [...current, role],
        );
    };

    const save = () => {
        setSaving(true);
        router.put(
            route('admin.users.roles.update', user.id),
            { roles: selected },
            {
                preserveScroll: true,
                onSuccess: () => setSaved(true),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <tr className={user.is_active ? '' : 'opacity-50'}>
            <td>
                <div className="font-medium text-[var(--color-text-primary)]">{user.name}</div>
                <div className="text-xs text-[var(--color-text-secondary)]">
                    {user.email}
                    {user.position ? ` · ${user.position}` : ''}
                    {!user.is_active ? ' · inactive' : ''}
                </div>
            </td>
            <td>
                <div className="flex flex-wrap gap-x-4 gap-y-1">
                    {roleNames.map((role) => (
                        <label key={role} className="flex items-center gap-1.5 whitespace-nowrap">
                            <Checkbox checked={selected.includes(role)} onChange={() => toggle(role)} />
                            <span className="text-xs text-gray-700">{role}</span>
                        </label>
                    ))}
                </div>
            </td>
            <td className="text-right">
                {/* Idle rows show a quiet ghost button; a dirty row lights it
                    up as the primary action so "unsaved changes" is visible
                    at a glance, and success flashes a Saved state. */}
                {dirty || saving ? (
                    <PrimaryButton disabled={saving} onClick={save} className="shadow-md ring-2 ring-[var(--color-accent)]/40">
                        {saving ? 'Saving…' : 'Save'}
                    </PrimaryButton>
                ) : saved ? (
                    <span className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-[var(--color-success)]">
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                        Saved
                    </span>
                ) : (
                    <span className="px-4 py-2 text-sm text-gray-300">No changes</span>
                )}
            </td>
        </tr>
    );
}

export default function Roles({ roles, permissionGroups, users }) {
    const createForm = useForm({ name: '' });

    const createRole = (e) => {
        e.preventDefault();
        createForm.post(route('admin.roles.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    return (
        <AuthenticatedLayout header="Roles & Permissions">
            <Head title="Roles & Permissions" />

            <PageHeader
                title="Roles & permissions"
                subtitle="Who may do what. Every change here is written to the audit trail with its before and after."
                actions={
                    <Link href={route('admin.users.index')} className="btn-secondary">
                        Manage users
                    </Link>
                }
            />

            <div className="space-y-6">
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        Roles
                    </h2>
                    {roles.map((role) => (
                        <RoleCard key={role.id} role={role} permissionGroups={permissionGroups} />
                    ))}

                    <form onSubmit={createRole} className="card">
                        <div className="card-body flex flex-wrap items-end gap-3">
                            <div className="min-w-[240px] flex-1">
                                <InputLabel htmlFor="new-role" value="New role name" />
                                <TextInput
                                    id="new-role"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    placeholder="e.g. Data Protection Officer"
                                />
                                <InputError message={createForm.errors.name} className="mt-1" />
                            </div>
                            <PrimaryButton type="submit" disabled={!createForm.data.name || createForm.processing}>
                                Create role
                            </PrimaryButton>
                        </div>
                    </form>
                </section>

                <section className="space-y-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                        User role assignments
                    </h2>
                    <div className="card overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Roles</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <UserRow key={user.id} user={user} roleNames={roles.map((r) => r.name)} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
