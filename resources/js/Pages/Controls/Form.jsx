import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';

/**
 * Shared control form used by Create and Edit, structured per the
 * thirdLine reference: Control Details → Classification →
 * Assessment Schedule → Ownership.
 */
export default function ControlForm({
    form,
    onSubmit,
    submitLabel = 'Save control',
    controlRef = null,
    categories = [],
    processes = [],
    units = [],
    users = [],
    types = [],
    natures = [],
    frequencies = [],
    cosoComponents = [],
    requireChangeReason = false,
}) {
    const { data, setData, processing, errors } = form;

    return (
        <form onSubmit={onSubmit} className="mx-auto max-w-4xl space-y-6">
            <div className="card">
                <div className="card-header">
                    <h3 className="text-sm font-semibold">Control Details</h3>
                </div>
                <div className="card-body space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="control_ref" value="Control Reference" />
                            <TextInput id="control_ref" value={controlRef ?? ''} disabled className="bg-gray-50 font-mono" />
                            <p className="mt-1 text-xs text-gray-400">Assigned automatically.</p>
                        </div>
                        <div>
                            <InputLabel htmlFor="title" value="Title" required />
                            <TextInput
                                id="title"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="e.g., Segregation of Duties - Treasury"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="Description" />
                        <TextArea
                            id="description"
                            rows={4}
                            value={data.description ?? ''}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Describe the control objective, what it does, and how it mitigates risk…"
                        />
                        <InputError message={errors.description} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="objective" value="Control objective" />
                        <TextArea id="objective" value={data.objective ?? ''} onChange={(e) => setData('objective', e.target.value)} />
                        <InputError message={errors.objective} className="mt-1" />
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="unit_id" value="Entity" />
                            <SelectInput id="unit_id" value={data.unit_id ?? ''} onChange={(e) => setData('unit_id', e.target.value || null)}>
                                <option value="">Select entity…</option>
                                {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                            <InputError message={errors.unit_id} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="department" value="Department" />
                            <TextInput
                                id="department"
                                value={data.department ?? ''}
                                onChange={(e) => setData('department', e.target.value)}
                                placeholder="e.g., Retail Banking"
                            />
                            <InputError message={errors.department} className="mt-1" />
                        </div>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <h3 className="text-sm font-semibold">Classification</h3>
                </div>
                <div className="card-body space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel htmlFor="type" value="Control Type" required />
                            <SelectInput id="type" value={data.type} onChange={(e) => setData('type', e.target.value)}>
                                {types.map((t) => <option key={t} value={t}>{t}</option>)}
                            </SelectInput>
                            <InputError message={errors.type} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="nature" value="Control Nature" required />
                            <SelectInput id="nature" value={data.nature} onChange={(e) => setData('nature', e.target.value)}>
                                {natures.map((n) => <option key={n} value={n}>{n}</option>)}
                            </SelectInput>
                            <InputError message={errors.nature} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="frequency" value="Frequency" required />
                            <SelectInput id="frequency" value={data.frequency} onChange={(e) => setData('frequency', e.target.value)}>
                                {frequencies.map((f) => <option key={f} value={f}>{f}</option>)}
                            </SelectInput>
                            <InputError message={errors.frequency} className="mt-1" />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="category_id" value="Control Category" />
                            <SelectInput id="category_id" value={data.category_id ?? ''} onChange={(e) => setData('category_id', e.target.value || null)}>
                                <option value="">Select category…</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </SelectInput>
                            <InputError message={errors.category_id} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="coso_component" value="COSO Component" />
                            <SelectInput id="coso_component" value={data.coso_component ?? ''} onChange={(e) => setData('coso_component', e.target.value || null)}>
                                <option value="">Select COSO component…</option>
                                {cosoComponents.map((c) => <option key={c} value={c}>{c}</option>)}
                            </SelectInput>
                            <InputError message={errors.coso_component} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="process_id" value="Business process" />
                            <SelectInput id="process_id" value={data.process_id ?? ''} onChange={(e) => setData('process_id', e.target.value || null)}>
                                <option value="">Select process…</option>
                                {processes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </SelectInput>
                            <InputError message={errors.process_id} className="mt-1" />
                        </div>
                    </div>

                    <label className="flex items-center gap-2">
                        <Checkbox checked={!!data.is_key_control} onChange={(e) => setData('is_key_control', e.target.checked)} />
                        <span className="text-sm text-gray-600">Key control (primary reliance for a material risk)</span>
                    </label>
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <h3 className="text-sm font-semibold">Assessment Schedule</h3>
                </div>
                <div className="card-body">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="test_frequency" value="Test Frequency" />
                            <SelectInput id="test_frequency" value={data.test_frequency ?? ''} onChange={(e) => setData('test_frequency', e.target.value || null)}>
                                <option value="">Select test frequency…</option>
                                {frequencies.map((f) => <option key={f} value={f}>{f}</option>)}
                            </SelectInput>
                            <InputError message={errors.test_frequency} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Status" />
                            <p className="mt-2 text-sm text-gray-500">
                                Managed by the approval workflow — new controls start as Draft and become Active once approved.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <h3 className="text-sm font-semibold">Ownership</h3>
                </div>
                <div className="card-body space-y-4">
                    <div>
                        <InputLabel htmlFor="owner_id" value="Control Owner" />
                        <SelectInput id="owner_id" value={data.owner_id ?? ''} onChange={(e) => setData('owner_id', e.target.value || null)}>
                            <option value="">Select owner…</option>
                            {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </SelectInput>
                        <InputError message={errors.owner_id} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="control_documentation" value="Control Documentation" />
                        <TextArea
                            id="control_documentation"
                            rows={3}
                            value={data.control_documentation ?? ''}
                            onChange={(e) => setData('control_documentation', e.target.value)}
                            placeholder="Reference to policy documents, procedures, or standards that support this control…"
                        />
                        <InputError message={errors.control_documentation} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="notes" value="Notes" />
                        <TextArea
                            id="notes"
                            rows={3}
                            value={data.notes ?? ''}
                            onChange={(e) => setData('notes', e.target.value)}
                            placeholder="Any additional notes or context…"
                        />
                        <InputError message={errors.notes} className="mt-1" />
                    </div>
                </div>
            </div>

            {requireChangeReason && (
                <div className="card">
                    <div className="card-body">
                        <InputLabel htmlFor="change_reason" value="Reason for change" required />
                        <TextArea
                            id="change_reason"
                            value={data.change_reason ?? ''}
                            onChange={(e) => setData('change_reason', e.target.value)}
                            placeholder="Every amendment is versioned — record why this change is being made."
                        />
                        <InputError message={errors.change_reason} className="mt-1" />
                    </div>
                </div>
            )}

            <div className="flex justify-end gap-2">
                <SecondaryButton onClick={() => window.history.back()}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}
