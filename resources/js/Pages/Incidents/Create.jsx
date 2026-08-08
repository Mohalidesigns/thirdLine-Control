import InputError from '@/Components/InputError';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

const nowLocal = () => {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());

    return now.toISOString().slice(0, 16);
};

export default function Create({
    types = [],
    baselEventTypes = [],
    severities = [],
    entities = [],
    processes = [],
    users = [],
    controls = [],
    risks = [],
}) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        incident_type: 'operational',
        basel_event_type: '',
        severity: 'Medium',
        occurred_at: nowLocal(),
        detected_at: nowLocal(),
        detection_method: '',
        entity_id: '',
        process_id: '',
        owner_id: '',
        near_miss: false,
        currency: '',
        gross_loss_minor: '',
        recovery_minor: '',
        control_failure_ids: [],
        risk_ids: [],
    });

    const toggle = (field, id) => {
        const current = data[field];
        setData(field, current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('incidents.store'));
    };

    return (
        <AuthenticatedLayout header="Report an incident">
            <Head title="Report an incident" />

            <PageHeader
                title="Report an incident"
                subtitle="Report first, classify later — the notification clock starts from when we became aware"
                breadcrumbs={[{ label: 'Incidents', href: route('incidents.index') }, { label: 'New' }]}
            />

            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6">
                <div className="card">
                    <div className="card-header">What happened</div>
                    <div className="card-body space-y-4">
                        <div>
                            <label className="form-label">
                                Title <span className="text-red-500">*</span>
                            </label>
                            <input
                                className="form-input"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <label className="form-label">Description</label>
                            <textarea
                                className="form-input"
                                rows={4}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="form-label">Type</label>
                                <select
                                    className="form-select"
                                    value={data.incident_type}
                                    onChange={(e) => setData('incident_type', e.target.value)}
                                >
                                    {types.map((type) => (
                                        <option key={type} value={type}>
                                            {type.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-gray-400">
                                    The type decides which regulators must be told, from the obligation register.
                                </p>
                            </div>

                            <div>
                                <label className="form-label">Severity</label>
                                <select
                                    className="form-select"
                                    value={data.severity}
                                    onChange={(e) => setData('severity', e.target.value)}
                                >
                                    {severities.map((severity) => (
                                        <option key={severity} value={severity}>
                                            {severity}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="form-label">Basel event type</label>
                                <select
                                    className="form-select"
                                    value={data.basel_event_type}
                                    onChange={(e) => setData('basel_event_type', e.target.value)}
                                >
                                    <option value="">—</option>
                                    {baselEventTypes.map((type) => (
                                        <option key={type} value={type}>
                                            {type}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="form-label">Detection method</label>
                                <input
                                    className="form-input"
                                    value={data.detection_method}
                                    onChange={(e) => setData('detection_method', e.target.value)}
                                    placeholder="Monitoring alert, customer report, internal review…"
                                />
                            </div>

                            <div>
                                <label className="form-label">Occurred at</label>
                                <input
                                    type="datetime-local"
                                    className="form-input"
                                    value={data.occurred_at}
                                    onChange={(e) => setData('occurred_at', e.target.value)}
                                />
                                <InputError message={errors.occurred_at} className="mt-1" />
                            </div>

                            <div>
                                <label className="form-label">Detected at</label>
                                <input
                                    type="datetime-local"
                                    className="form-input"
                                    value={data.detected_at}
                                    onChange={(e) => setData('detected_at', e.target.value)}
                                />
                                <InputError message={errors.detected_at} className="mt-1" />
                            </div>
                        </div>

                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5"
                                checked={data.near_miss}
                                onChange={(e) => setData('near_miss', e.target.checked)}
                            />
                            <span>
                                This was a near miss
                                <span className="block text-xs text-gray-400">
                                    Near misses carry no loss but are the leading indicator — record them.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">Where</div>
                    <div className="card-body grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label className="form-label">Entity</label>
                            <select
                                className="form-select"
                                value={data.entity_id}
                                onChange={(e) => setData('entity_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {entities.map((entity) => (
                                    <option key={entity.id} value={entity.id}>
                                        {entity.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Process</label>
                            <select
                                className="form-select"
                                value={data.process_id}
                                onChange={(e) => setData('process_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {processes.map((process) => (
                                    <option key={process.id} value={process.id}>
                                        {process.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Owner</label>
                            <select
                                className="form-select"
                                value={data.owner_id}
                                onChange={(e) => setData('owner_id', e.target.value)}
                            >
                                <option value="">—</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">Loss</div>
                    <div className="card-body grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label className="form-label">Currency</label>
                            <select
                                className="form-select"
                                value={data.currency}
                                onChange={(e) => setData('currency', e.target.value)}
                            >
                                <option value="">—</option>
                                {['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'].map((currency) => (
                                    <option key={currency} value={currency}>
                                        {currency}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Gross loss (minor units)</label>
                            <input
                                type="number"
                                min="0"
                                className="form-input"
                                value={data.gross_loss_minor}
                                onChange={(e) => setData('gross_loss_minor', e.target.value)}
                            />
                            <InputError message={errors.gross_loss_minor} className="mt-1" />
                        </div>

                        <div>
                            <label className="form-label">Recovery (minor units)</label>
                            <input
                                type="number"
                                min="0"
                                className="form-input"
                                value={data.recovery_minor}
                                onChange={(e) => setData('recovery_minor', e.target.value)}
                            />
                            <InputError message={errors.recovery_minor} className="mt-1" />
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">Controls that failed</div>
                    <div className="card-body">
                        <p className="mb-3 text-xs text-gray-400">
                            Naming a control here flags it for re-testing and raises an exception against it.
                        </p>
                        <div className="max-h-56 space-y-1 overflow-y-auto">
                            {controls.map((control) => (
                                <label key={control.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={data.control_failure_ids.includes(control.id)}
                                        onChange={() => toggle('control_failure_ids', control.id)}
                                    />
                                    <span className="font-mono text-xs">{control.control_ref}</span>
                                    <span>{control.title}</span>
                                </label>
                            ))}
                            {controls.length === 0 && <p className="text-sm text-gray-400">No active controls.</p>}
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">Risks this bears on</div>
                    <div className="card-body max-h-56 space-y-1 overflow-y-auto">
                        {risks.map((risk) => (
                            <label key={risk.id} className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.risk_ids.includes(risk.id)}
                                    onChange={() => toggle('risk_ids', risk.id)}
                                />
                                <span className="font-mono text-xs">{risk.code}</span>
                                <span>{risk.title}</span>
                            </label>
                        ))}
                        {risks.length === 0 && <p className="text-sm text-gray-400">No risks on the register.</p>}
                    </div>
                </div>

                <div className="flex justify-end">
                    <button type="submit" className="btn-primary" disabled={processing}>
                        {processing ? 'Recording…' : 'Record incident'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
