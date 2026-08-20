import Checkbox from '@/Components/Checkbox';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import StatusBadge from '@/Components/StatusBadge';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    Download,
    KeyRound,
    MailPlus,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
    UserPlus,
    Users as UsersIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** Split a stored full name into the form's first/last fields. */
function splitName(name = '') {
    const parts = name.trim().split(/\s+/);
    return { first: parts[0] ?? '', last: parts.slice(1).join(' ') };
}

function UserFormModal({ show, onClose, user, roles, units, managers }) {
    const isEdit = Boolean(user);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        position: '',
        unit_id: '',
        reports_to: '',
        roles: [],
        password_mode: 'invite',
        temporary_password: '',
    });

    // Re-seed whenever the modal opens for a different target.
    useEffect(() => {
        if (!show) return;
        clearErrors();
        if (user) {
            const { first, last } = splitName(user.name);
            setData({
                first_name: first,
                last_name: last,
                email: user.email,
                phone: user.phone ?? '',
                position: user.position ?? '',
                unit_id: user.unit?.id ?? '',
                reports_to: user.manager?.id ?? '',
                roles: user.roles,
                password_mode: 'invite',
                temporary_password: '',
            });
        } else {
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, user?.id]);

    const toggleRole = (role) =>
        setData('roles', data.roles.includes(role) ? data.roles.filter((r) => r !== role) : [...data.roles, role]);

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (isEdit) {
            put(route('admin.users.update', user.id), options);
        } else {
            post(route('admin.users.store'), options);
        }
    };

    return (
        <Modal show={show} maxWidth="2xl" onClose={onClose} title={isEdit ? `Edit ${user?.name}` : 'Add user'}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="first_name" value="First name" required />
                        <TextInput
                            id="first_name"
                            value={data.first_name}
                            isFocused
                            onChange={(e) => setData('first_name', e.target.value)}
                        />
                        <InputError message={errors.first_name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="last_name" value="Last name" required />
                        <TextInput id="last_name" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} />
                        <InputError message={errors.last_name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="email" value="Email" required />
                        <TextInput id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="phone" value="Phone (optional)" />
                        <TextInput id="phone" value={data.phone} placeholder="+2348012345678" onChange={(e) => setData('phone', e.target.value)} />
                        <InputError message={errors.phone} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="position" value="Job title" />
                        <TextInput id="position" value={data.position} onChange={(e) => setData('position', e.target.value)} />
                        <InputError message={errors.position} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="unit_id" value="Entity / business unit" />
                        <SelectInput id="unit_id" value={data.unit_id} onChange={(e) => setData('unit_id', e.target.value)}>
                            <option value="">—</option>
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>{unit.name}</option>
                            ))}
                        </SelectInput>
                        <InputError message={errors.unit_id} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="reports_to" value="Line manager" />
                        <SelectInput id="reports_to" value={data.reports_to} onChange={(e) => setData('reports_to', e.target.value)}>
                            <option value="">—</option>
                            {managers
                                .filter((manager) => manager.id !== user?.id)
                                .map((manager) => (
                                    <option key={manager.id} value={manager.id}>{manager.name}</option>
                                ))}
                        </SelectInput>
                        <InputError message={errors.reports_to} className="mt-1" />
                    </div>
                </div>

                <div>
                    <InputLabel value="Roles" required />
                    <div className="mt-1 grid gap-1.5 sm:grid-cols-2">
                        {roles.map((role) => (
                            <label key={role} className="flex items-center gap-2">
                                <Checkbox checked={data.roles.includes(role)} onChange={() => toggleRole(role)} />
                                <span className="text-sm text-gray-700">{role}</span>
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.roles} className="mt-1" />
                </div>

                {!isEdit && (
                    <div className="rounded-lg border border-gray-200 p-4">
                        <InputLabel value="Password" required />
                        <div className="mt-2 space-y-2">
                            <label className="flex items-start gap-2">
                                <input
                                    type="radio"
                                    name="password_mode"
                                    className="mt-0.5 border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                    checked={data.password_mode === 'invite'}
                                    onChange={() => setData('password_mode', 'invite')}
                                />
                                <span className="text-sm text-gray-700">
                                    <span className="font-medium">Send an email invitation</span>
                                    <span className="block text-xs text-[var(--color-text-secondary)]">
                                        A signed set-password link, valid for 7 days.
                                    </span>
                                </span>
                            </label>
                            <label className="flex items-start gap-2">
                                <input
                                    type="radio"
                                    name="password_mode"
                                    className="mt-0.5 border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                    checked={data.password_mode === 'temporary'}
                                    onChange={() => setData('password_mode', 'temporary')}
                                />
                                <span className="text-sm text-gray-700">
                                    <span className="font-medium">Set a temporary password</span>
                                    <span className="block text-xs text-[var(--color-text-secondary)]">
                                        They must change it at first login.
                                    </span>
                                </span>
                            </label>
                            {data.password_mode === 'temporary' && (
                                <div className="ps-6">
                                    <TextInput
                                        type="password"
                                        value={data.temporary_password}
                                        placeholder="Temporary password"
                                        autoComplete="new-password"
                                        onChange={(e) => setData('temporary_password', e.target.value)}
                                    />
                                    <InputError message={errors.temporary_password} className="mt-1" />
                                </div>
                            )}
                        </div>
                    </div>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <SecondaryButton onClick={onClose} disabled={processing}>Cancel</SecondaryButton>
                    <PrimaryButton type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save changes' : data.password_mode === 'invite' ? 'Create & send invite' : 'Create user'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function RowAction({ icon: Icon, label, onClick, tone = 'default' }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            onClick={onClick}
            className={`rounded-lg p-1.5 transition-colors ${
                tone === 'danger'
                    ? 'text-gray-400 hover:bg-red-50 hover:text-[var(--color-error)]'
                    : 'text-gray-400 hover:bg-gray-100 hover:text-[var(--color-primary)]'
            }`}
        >
            <Icon className="h-4 w-4" strokeWidth={1.8} />
        </button>
    );
}

export default function Index({ users, filters, roles, units, managers }) {
    const { auth } = usePage().props;
    const [modal, setModal] = useState({ open: false, user: null });
    const [confirm, setConfirm] = useState(null); // { title, message, confirmLabel, action }
    const [selected, setSelected] = useState([]);
    const [bulkRole, setBulkRole] = useState('');
    const [search, setSearch] = useState(filters.search ?? '');
    const searchTimer = useRef(null);

    const applyFilters = (overrides = {}) => {
        router.get(route('admin.users.index'), {
            search: search || undefined,
            role: filters.role || undefined,
            unit: filters.unit || undefined,
            status: filters.status || undefined,
            ...overrides,
        }, { preserveState: true, replace: true });
    };

    const onSearch = (value) => {
        setSearch(value);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => applyFilters({ search: value || undefined }), 350);
    };

    const rows = users.data;
    const allSelected = rows.length > 0 && rows.every((user) => selected.includes(user.id));
    const toggleAll = () => setSelected(allSelected ? [] : rows.map((user) => user.id));
    const toggleOne = (id) =>
        setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    const act = (routeName, user, extra = {}) =>
        router.post(route(routeName, user.id), extra, { preserveScroll: true, onSuccess: () => setConfirm(null) });

    const bulk = (action, extra = {}) =>
        router.post(route('admin.users.bulk'), { action, ids: selected, ...extra }, {
            preserveScroll: true,
            onSuccess: () => { setSelected([]); setBulkRole(''); setConfirm(null); },
        });

    const exportSelectedCsv = () => {
        const chosen = rows.filter((user) => selected.includes(user.id));
        const lines = [
            ['Name', 'Email', 'Job title', 'Unit', 'Roles', 'Status', 'Last login'],
            ...chosen.map((user) => [
                user.name, user.email, user.position ?? '', user.unit?.name ?? '',
                user.roles.join('; '), user.status, user.last_login_at ?? '',
            ]),
        ];
        const csv = lines.map((line) => line.map((cell) => `"${String(cell).replaceAll('"', '""')}"`).join(',')).join('\n');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
        link.download = 'users-selection.csv';
        link.click();
        URL.revokeObjectURL(link.href);
    };

    const formatLastLogin = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : 'Never');

    return (
        <AuthenticatedLayout header="Users">
            <Head title="Users" />

            <PageHeader
                title="Users"
                subtitle="Create, invite and manage accounts. Every change here is written to the audit trail with its before and after."
                actions={
                    <>
                        <Link href={route('admin.roles')} className="btn-secondary">
                            <KeyRound className="h-4 w-4" strokeWidth={1.8} /> Manage roles & permissions
                        </Link>
                        <a href={route('admin.users.export')} className="btn-secondary">
                            <Download className="h-4 w-4" strokeWidth={1.8} /> Export CSV
                        </a>
                        <PrimaryButton onClick={() => setModal({ open: true, user: null })}>
                            <Plus className="h-4 w-4" strokeWidth={2} /> Add user
                        </PrimaryButton>
                    </>
                }
            />

            <div className="card mb-4">
                <div className="card-body grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <InputLabel htmlFor="user-search" value="Search" />
                        <TextInput
                            id="user-search"
                            value={search}
                            placeholder="Name or email…"
                            onChange={(e) => onSearch(e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel htmlFor="filter-role" value="Role" />
                        <SelectInput id="filter-role" value={filters.role ?? ''} onChange={(e) => applyFilters({ role: e.target.value || undefined })}>
                            <option value="">All</option>
                            {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel htmlFor="filter-unit" value="Entity / unit" />
                        <SelectInput id="filter-unit" value={filters.unit ?? ''} onChange={(e) => applyFilters({ unit: e.target.value || undefined })}>
                            <option value="">All</option>
                            {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                        </SelectInput>
                    </div>
                    <div>
                        <InputLabel htmlFor="filter-status" value="Status" />
                        <SelectInput id="filter-status" value={filters.status ?? ''} onChange={(e) => applyFilters({ status: e.target.value || undefined })}>
                            <option value="">All</option>
                            <option>Active</option>
                            <option>Invited</option>
                            <option>Suspended</option>
                        </SelectInput>
                    </div>
                </div>
            </div>

            {selected.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-[var(--color-primary)]/20 bg-[var(--color-primary)]/5 px-4 py-3">
                    <p className="text-sm font-medium text-[var(--color-text-primary)]">{selected.length} selected</p>
                    <div className="flex items-center gap-2">
                        <SelectInput value={bulkRole} onChange={(e) => setBulkRole(e.target.value)} className="!py-1.5 text-sm">
                            <option value="">Assign role…</option>
                            {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                        </SelectInput>
                        <SecondaryButton disabled={!bulkRole} onClick={() => bulk('assign-role', { role: bulkRole })}>
                            Apply
                        </SecondaryButton>
                    </div>
                    <SecondaryButton
                        onClick={() => setConfirm({
                            title: `Suspend ${selected.length} user(s)?`,
                            message: 'Suspended users cannot sign in until reactivated.',
                            confirmLabel: 'Suspend',
                            action: () => bulk('suspend'),
                        })}
                    >
                        <Ban className="h-4 w-4" strokeWidth={1.8} /> Suspend
                    </SecondaryButton>
                    <SecondaryButton onClick={exportSelectedCsv}>
                        <Download className="h-4 w-4" strokeWidth={1.8} /> Export selection
                    </SecondaryButton>
                </div>
            )}

            <div className="card overflow-x-auto">
                {rows.length === 0 ? (
                    <EmptyState
                        icon={<UsersIcon className="h-10 w-10 text-gray-300" strokeWidth={1.5} />}
                        title="No users match"
                        description="Adjust the filters, or add the first user to get started."
                        action={
                            <PrimaryButton onClick={() => setModal({ open: true, user: null })}>
                                <UserPlus className="h-4 w-4" strokeWidth={2} /> Add user
                            </PrimaryButton>
                        }
                    />
                ) : (
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th className="w-10"><Checkbox checked={allSelected} onChange={toggleAll} /></th>
                                <th>Name</th>
                                <th>Job title</th>
                                <th>Entity / unit</th>
                                <th>Roles</th>
                                <th>Status</th>
                                <th>Last login</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((user) => (
                                <tr key={user.id} className={user.status === 'Suspended' ? 'opacity-60' : ''}>
                                    <td><Checkbox checked={selected.includes(user.id)} onChange={() => toggleOne(user.id)} /></td>
                                    <td>
                                        <div className="font-medium text-[var(--color-text-primary)]">{user.name}</div>
                                        <div className="text-xs text-[var(--color-text-secondary)]">{user.email}</div>
                                    </td>
                                    <td className="text-sm">{user.position ?? '—'}</td>
                                    <td className="text-sm">{user.unit?.name ?? '—'}</td>
                                    <td>
                                        <div className="flex max-w-xs flex-wrap gap-1">
                                            {user.roles.map((role) => (
                                                <span key={role} className="badge badge-status-draft">{role}</span>
                                            ))}
                                        </div>
                                    </td>
                                    <td><StatusBadge status={user.status} /></td>
                                    <td className="text-sm text-[var(--color-text-secondary)]">{formatLastLogin(user.last_login_at)}</td>
                                    <td>
                                        <div className="flex items-center justify-end gap-0.5">
                                            <RowAction icon={Pencil} label="Edit" onClick={() => setModal({ open: true, user })} />
                                            {user.status === 'Invited' && (
                                                <RowAction
                                                    icon={MailPlus}
                                                    label="Resend invite"
                                                    onClick={() => act('admin.users.resend-invite', user)}
                                                />
                                            )}
                                            <RowAction
                                                icon={KeyRound}
                                                label="Send password reset link"
                                                onClick={() => setConfirm({
                                                    title: `Reset password for ${user.name}?`,
                                                    message: `A signed reset link will be emailed to ${user.email}.`,
                                                    confirmLabel: 'Send link',
                                                    variant: 'primary',
                                                    action: () => act('admin.users.reset-password', user),
                                                })}
                                            />
                                            {user.status === 'Suspended' ? (
                                                <RowAction
                                                    icon={RotateCcw}
                                                    label="Reactivate"
                                                    onClick={() => act('admin.users.reactivate', user)}
                                                />
                                            ) : (
                                                user.id !== auth.user.id && (
                                                    <RowAction
                                                        icon={Ban}
                                                        label="Suspend"
                                                        onClick={() => setConfirm({
                                                            title: `Suspend ${user.name}?`,
                                                            message: 'They will not be able to sign in until reactivated.',
                                                            confirmLabel: 'Suspend',
                                                            action: () => act('admin.users.suspend', user),
                                                        })}
                                                    />
                                                )
                                            )}
                                            {user.id !== auth.user.id && (
                                                <RowAction
                                                    icon={Trash2}
                                                    label="Delete"
                                                    tone="danger"
                                                    onClick={() => setConfirm({
                                                        title: `Delete ${user.name}?`,
                                                        message: 'This is a soft delete — their historical activity on controls, tests, exceptions and evidence is preserved.',
                                                        confirmLabel: 'Delete',
                                                        action: () => router.delete(route('admin.users.destroy', user.id), {
                                                            preserveScroll: true,
                                                            onSuccess: () => setConfirm(null),
                                                        }),
                                                    })}
                                                />
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                <Pagination paginator={users} />
            </div>

            <UserFormModal
                show={modal.open}
                onClose={() => setModal({ open: false, user: null })}
                user={modal.user}
                roles={roles}
                units={units}
                managers={managers}
            />

            <ConfirmDialog
                show={Boolean(confirm)}
                title={confirm?.title}
                message={confirm?.message}
                confirmLabel={confirm?.confirmLabel}
                variant={confirm?.variant ?? 'danger'}
                onConfirm={() => confirm?.action()}
                onCancel={() => setConfirm(null)}
            />
        </AuthenticatedLayout>
    );
}
