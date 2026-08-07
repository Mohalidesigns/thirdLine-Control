import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, useForm } from '@inertiajs/react';
import { useMemo } from 'react';

const CHANNEL_LABELS = {
    in_app: 'In-app',
    email: 'Email',
    whatsapp: 'WhatsApp',
    sms: 'SMS',
    push: 'Push',
};

// WhatsApp, SMS and push dispatch arrives in a later release; preferences
// can be set now but nothing is delivered on those channels yet.
const PENDING_CHANNELS = ['whatsapp', 'sms', 'push'];

export default function Notifications({ events, preferences, channels, digestFrequencies }) {
    const initial = useMemo(() => {
        const rows = [];
        events.forEach((event) => {
            channels.forEach((channel) => {
                const existing = preferences.find(
                    (p) => p.event_key === event.key && p.channel === channel,
                );
                rows.push({
                    event_key: event.key,
                    channel,
                    is_enabled: existing ? !!existing.is_enabled : event.default_channels.includes(channel),
                    digest_frequency: existing?.digest_frequency ?? 'immediate',
                    quiet_hours_start: existing?.quiet_hours_start?.slice(0, 5) ?? null,
                    quiet_hours_end: existing?.quiet_hours_end?.slice(0, 5) ?? null,
                });
            });
        });
        return rows;
    }, [events, preferences, channels]);

    const { data, setData, put, processing } = useForm({ preferences: initial });

    const rowFor = (eventKey, channel) =>
        data.preferences.find((p) => p.event_key === eventKey && p.channel === channel);

    const setRow = (eventKey, channel, patch) => {
        setData(
            'preferences',
            data.preferences.map((p) =>
                p.event_key === eventKey && p.channel === channel ? { ...p, ...patch } : p,
            ),
        );
    };

    const categories = [...new Set(events.map((e) => e.category))];

    return (
        <AuthenticatedLayout header="Notification Preferences">
            <Head title="Notification Preferences" />

            <PageHeader
                title="Notification preferences"
                subtitle="Choose how each event reaches you. Digest frequencies batch non-urgent events; quiet hours defer delivery."
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    put(route('settings.notifications.update'), { preserveScroll: true });
                }}
            >
                {categories.map((category) => (
                    <div key={category} className="card mb-6">
                        <div className="card-header">
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                                {category}
                            </h3>
                        </div>
                        <div className="card-body space-y-6">
                            {events
                                .filter((event) => event.category === category)
                                .map((event) => (
                                    <div key={event.key}>
                                        <p className="text-sm font-semibold text-[var(--color-text-primary)]">{event.label}</p>
                                        <p className="mb-3 text-xs text-[var(--color-text-secondary)]">{event.description}</p>

                                        {!event.is_user_configurable ? (
                                            <p className="text-xs italic text-[var(--color-text-secondary)]">
                                                Security event — delivery is fixed by policy.
                                            </p>
                                        ) : (
                                            <div className="flex flex-wrap gap-4">
                                                {channels.map((channel) => {
                                                    const row = rowFor(event.key, channel);
                                                    if (!row) return null;
                                                    const pending = PENDING_CHANNELS.includes(channel);
                                                    return (
                                                        <div key={channel} className="min-w-[130px]">
                                                            <label className="flex items-center gap-2">
                                                                <input
                                                                    type="checkbox"
                                                                    className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                                                    checked={row.is_enabled}
                                                                    disabled={pending}
                                                                    onChange={(e) =>
                                                                        setRow(event.key, channel, { is_enabled: e.target.checked })
                                                                    }
                                                                />
                                                                <span className={`text-sm ${pending ? 'text-gray-400' : 'text-gray-700'}`}>
                                                                    {CHANNEL_LABELS[channel]}
                                                                    {pending && ' (soon)'}
                                                                </span>
                                                            </label>
                                                            {row.is_enabled && !pending && (
                                                                <select
                                                                    className="form-select mt-1 text-xs"
                                                                    value={row.digest_frequency}
                                                                    onChange={(e) =>
                                                                        setRow(event.key, channel, { digest_frequency: e.target.value })
                                                                    }
                                                                >
                                                                    {digestFrequencies.map((f) => (
                                                                        <option key={f} value={f}>
                                                                            {f.charAt(0).toUpperCase() + f.slice(1)}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                ))}
                        </div>
                    </div>
                ))}

                <div className="card mb-6">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-secondary)]">
                            Quiet hours
                        </h3>
                    </div>
                    <div className="card-body">
                        <p className="mb-3 text-xs text-[var(--color-text-secondary)]">
                            Email deliveries inside this window are held until it ends. Applies to all configurable events, in your
                            organisation's timezone.
                        </p>
                        <div className="flex flex-wrap items-end gap-3">
                            <div>
                                <label className="form-label">From</label>
                                <input
                                    type="time"
                                    className="form-input"
                                    value={data.preferences.find((p) => p.channel === 'email')?.quiet_hours_start ?? ''}
                                    onChange={(e) =>
                                        setData(
                                            'preferences',
                                            data.preferences.map((p) =>
                                                p.channel === 'email' ? { ...p, quiet_hours_start: e.target.value || null } : p,
                                            ),
                                        )
                                    }
                                />
                            </div>
                            <div>
                                <label className="form-label">Until</label>
                                <input
                                    type="time"
                                    className="form-input"
                                    value={data.preferences.find((p) => p.channel === 'email')?.quiet_hours_end ?? ''}
                                    onChange={(e) =>
                                        setData(
                                            'preferences',
                                            data.preferences.map((p) =>
                                                p.channel === 'email' ? { ...p, quiet_hours_end: e.target.value || null } : p,
                                            ),
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <PrimaryButton disabled={processing}>Save preferences</PrimaryButton>
            </form>
        </AuthenticatedLayout>
    );
}
