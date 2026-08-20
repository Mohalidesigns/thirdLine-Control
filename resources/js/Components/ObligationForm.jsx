import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';

const ANCHORS = [
    { value: 'period_end', label: 'End of the reporting period' },
    { value: 'period_start', label: 'Start of the reporting period' },
    { value: 'fiscal_year_end', label: 'Entity fiscal year end' },
    { value: 'fiscal_year_start', label: 'Entity fiscal year start' },
    { value: 'incorporation_date', label: 'Date of incorporation' },
];

/**
 * Shared create/edit form. The due rule is the heart of it: everything the
 * compliance calendar does is derived from these few fields, which is why
 * they are data rather than code.
 */
export default function ObligationForm({ form, options, submitLabel }) {
    const { data, setData, errors, processing } = form;
    const ruleType = data.due_rule?.type ?? 'fixed_date';

    const setRule = (key, value) => setData('due_rule', { ...data.due_rule, [key]: value });

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <div className="card lg:col-span-2">
                <div className="card-header">
                    <h2 className="text-sm font-semibold">Obligation</h2>
                </div>
                <div className="card-body grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="obligation_ref" value="Reference" />
                        <TextInput
                            id="obligation_ref"
                            value={data.obligation_ref}
                            onChange={(e) => setData('obligation_ref', e.target.value)}
                            className="mt-1 block w-full"
                            placeholder="e.g. CBN-CG-2023-BOARD-EVAL"
                            required
                        />
                        <InputError message={errors.obligation_ref} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="regulator_id" value="Regulator" />
                        <SelectInput
                            id="regulator_id"
                            value={data.regulator_id ?? ''}
                            onChange={(e) => setData('regulator_id', e.target.value)}
                            className="mt-1 block w-full"
                            required
                        >
                            <option value="">Select a regulator…</option>
                            {options.regulators.map((regulator) => (
                                <option key={regulator.id} value={regulator.id}>
                                    {regulator.name} ({regulator.jurisdiction})
                                </option>
                            ))}
                        </SelectInput>
                        <InputError message={errors.regulator_id} className="mt-1" />
                    </div>

                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="title" value="Title" />
                        <TextInput
                            id="title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            className="mt-1 block w-full"
                            required
                        />
                        <InputError message={errors.title} className="mt-1" />
                    </div>

                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="description" value="Description" />
                        <TextArea
                            id="description"
                            value={data.description ?? ''}
                            onChange={(e) => setData('description', e.target.value)}
                            className="mt-1 block w-full"
                            rows={3}
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="obligation_type" value="Type" />
                        <SelectInput
                            id="obligation_type"
                            value={data.obligation_type}
                            onChange={(e) => setData('obligation_type', e.target.value)}
                            className="mt-1 block w-full"
                        >
                            {options.types.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel htmlFor="frequency" value="Frequency" />
                        <SelectInput
                            id="frequency"
                            value={data.frequency}
                            onChange={(e) => setData('frequency', e.target.value)}
                            className="mt-1 block w-full"
                        >
                            {options.frequencies.map((frequency) => (
                                <option key={frequency} value={frequency}>
                                    {frequency.replace('_', ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel htmlFor="trigger_type" value="Trigger" />
                        <SelectInput
                            id="trigger_type"
                            value={data.trigger_type}
                            onChange={(e) => setData('trigger_type', e.target.value)}
                            className="mt-1 block w-full"
                        >
                            {options.triggerTypes.map((trigger) => (
                                <option key={trigger} value={trigger}>
                                    {trigger.replace('_', ' ')}
                                </option>
                            ))}
                        </SelectInput>
                    </div>

                    <div>
                        <InputLabel htmlFor="grace_period_days" value="Grace period (days)" />
                        <TextInput
                            id="grace_period_days"
                            type="number"
                            min="0"
                            value={data.grace_period_days ?? 0}
                            onChange={(e) => setData('grace_period_days', e.target.value)}
                            className="mt-1 block w-full"
                        />
                    </div>

                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="legal_reference" value="Legal reference" />
                        <TextInput
                            id="legal_reference"
                            value={data.legal_reference ?? ''}
                            onChange={(e) => setData('legal_reference', e.target.value)}
                            className="mt-1 block w-full"
                            placeholder="e.g. s.66 BOFIA 2020"
                        />
                    </div>

                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="source_url" value="Source URL" />
                        <TextInput
                            id="source_url"
                            type="url"
                            value={data.source_url ?? ''}
                            onChange={(e) => setData('source_url', e.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={errors.source_url} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="effective_from" value="Effective from" />
                        <TextInput
                            id="effective_from"
                            type="date"
                            value={data.effective_from ?? ''}
                            onChange={(e) => setData('effective_from', e.target.value)}
                            className="mt-1 block w-full"
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="effective_to" value="Effective to" />
                        <TextInput
                            id="effective_to"
                            type="date"
                            value={data.effective_to ?? ''}
                            onChange={(e) => setData('effective_to', e.target.value)}
                            className="mt-1 block w-full"
                        />
                        <InputError message={errors.effective_to} className="mt-1" />
                    </div>
                </div>
            </div>

            <div className="space-y-6">
                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Due rule</h2>
                    </div>
                    <div className="card-body space-y-4">
                        <div>
                            <InputLabel htmlFor="due_rule_type" value="Rule type" />
                            <SelectInput
                                id="due_rule_type"
                                value={ruleType}
                                onChange={(e) => setData('due_rule', { type: e.target.value })}
                                className="mt-1 block w-full"
                            >
                                <option value="fixed_date">Fixed calendar date</option>
                                <option value="relative">Relative to an anchor</option>
                                <option value="event_relative">Relative to an event</option>
                            </SelectInput>
                            <InputError message={errors['due_rule.type']} className="mt-1" />
                        </div>

                        {ruleType === 'fixed_date' && (
                            <>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel htmlFor="rule_month" value="Month" />
                                        <TextInput
                                            id="rule_month"
                                            type="number"
                                            min="1"
                                            max="12"
                                            value={data.due_rule?.month ?? ''}
                                            onChange={(e) => setRule('month', e.target.value ? Number(e.target.value) : null)}
                                            className="mt-1 block w-full"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="rule_day" value="Day" />
                                        <TextInput
                                            id="rule_day"
                                            type="number"
                                            min="1"
                                            max="31"
                                            value={data.due_rule?.day ?? ''}
                                            onChange={(e) => setRule('day', e.target.value ? Number(e.target.value) : null)}
                                            className="mt-1 block w-full"
                                        />
                                    </div>
                                </div>
                                <p className="text-xs text-gray-400">
                                    Resolves to the first occurrence after the period it reports on. Leave the month blank
                                    for a monthly deadline such as “by the 21st”.
                                </p>
                            </>
                        )}

                        {ruleType === 'relative' && (
                            <>
                                <div>
                                    <InputLabel htmlFor="rule_anchor" value="Anchor" />
                                    <SelectInput
                                        id="rule_anchor"
                                        value={data.due_rule?.anchor ?? 'period_end'}
                                        onChange={(e) => setRule('anchor', e.target.value)}
                                        className="mt-1 block w-full"
                                    >
                                        {ANCHORS.map((anchor) => (
                                            <option key={anchor.value} value={anchor.value}>
                                                {anchor.label}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel htmlFor="rule_offset_days" value="Offset (days)" />
                                        <TextInput
                                            id="rule_offset_days"
                                            type="number"
                                            value={data.due_rule?.offset_days ?? 0}
                                            onChange={(e) => setRule('offset_days', Number(e.target.value))}
                                            className="mt-1 block w-full"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="rule_offset_months" value="Offset (months)" />
                                        <TextInput
                                            id="rule_offset_months"
                                            type="number"
                                            value={data.due_rule?.offset_months ?? 0}
                                            onChange={(e) => setRule('offset_months', Number(e.target.value))}
                                            className="mt-1 block w-full"
                                        />
                                    </div>
                                </div>
                            </>
                        )}

                        {ruleType === 'event_relative' && (
                            <>
                                <div>
                                    <InputLabel htmlFor="rule_event" value="Event" />
                                    <TextInput
                                        id="rule_event"
                                        value={data.due_rule?.event ?? ''}
                                        onChange={(e) => setRule('event', e.target.value)}
                                        className="mt-1 block w-full"
                                        placeholder="e.g. breach_detected"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="rule_offset_hours" value="Offset (hours)" />
                                    <TextInput
                                        id="rule_offset_hours"
                                        type="number"
                                        min="0"
                                        value={data.due_rule?.offset_hours ?? 72}
                                        onChange={(e) => setRule('offset_hours', Number(e.target.value))}
                                        className="mt-1 block w-full"
                                    />
                                </div>
                                <p className="text-xs text-gray-400">
                                    An hour-precise deadline is a wall-clock countdown: it does not roll off a weekend or
                                    public holiday.
                                </p>
                            </>
                        )}

                        <div>
                            <InputLabel htmlFor="rule_roll" value="If it lands on a non-business day" />
                            <SelectInput
                                id="rule_roll"
                                value={data.due_rule?.roll ?? 'next_business_day'}
                                onChange={(e) => setRule('roll', e.target.value)}
                                className="mt-1 block w-full"
                            >
                                <option value="next_business_day">Roll forward to the next business day</option>
                                <option value="previous_business_day">Roll back to the previous business day</option>
                                <option value="none">Leave it where it falls</option>
                            </SelectInput>
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Penalty</h2>
                    </div>
                    <div className="card-body space-y-4">
                        <div>
                            <InputLabel htmlFor="penalty_basis" value="Basis" />
                            <SelectInput
                                id="penalty_basis"
                                value={data.penalty_basis ?? ''}
                                onChange={(e) => setData('penalty_basis', e.target.value || null)}
                                className="mt-1 block w-full"
                            >
                                <option value="">No stated penalty</option>
                                {options.penaltyBases.map((basis) => (
                                    <option key={basis} value={basis}>
                                        {basis.replace('_', ' ')}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <InputLabel
                                    htmlFor="penalty_amount_minor"
                                    value={data.penalty_basis === 'percentage' ? 'Rate (basis points)' : 'Amount (minor units)'}
                                />
                                <TextInput
                                    id="penalty_amount_minor"
                                    type="number"
                                    min="0"
                                    value={data.penalty_amount_minor ?? ''}
                                    onChange={(e) =>
                                        setData('penalty_amount_minor', e.target.value === '' ? null : Number(e.target.value))
                                    }
                                    className="mt-1 block w-full"
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    {data.penalty_basis === 'percentage'
                                        ? '100 basis points = 1.00%'
                                        : '₦500,000 is entered as 50000000 kobo'}
                                </p>
                                <InputError message={errors.penalty_amount_minor} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="penalty_currency" value="Currency" />
                                <SelectInput
                                    id="penalty_currency"
                                    value={data.penalty_currency ?? ''}
                                    onChange={(e) => setData('penalty_currency', e.target.value || null)}
                                    className="mt-1 block w-full"
                                >
                                    <option value="">—</option>
                                    {options.currencies.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </SelectInput>
                                <InputError message={errors.penalty_currency} className="mt-1" />
                            </div>
                        </div>

                        {(data.penalty_basis === 'per_day' || data.penalty_basis === 'per_week') && (
                            <div>
                                <InputLabel
                                    htmlFor="penalty_fixed_amount_minor"
                                    value="Fixed amount on default (minor units)"
                                />
                                <TextInput
                                    id="penalty_fixed_amount_minor"
                                    type="number"
                                    min="0"
                                    value={data.penalty_fixed_amount_minor ?? ''}
                                    onChange={(e) =>
                                        setData(
                                            'penalty_fixed_amount_minor',
                                            e.target.value === '' ? null : Number(e.target.value),
                                        )
                                    }
                                    className="mt-1 block w-full"
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    For a two-part penalty — a sum on default plus the recurring amount above.
                                    The NCC&rsquo;s ₦3,000,000 plus ₦300,000 a day is entered as 300000000 here.
                                    Leave blank if the penalty is purely recurring.
                                </p>
                                <InputError message={errors.penalty_fixed_amount_minor} className="mt-1" />
                            </div>
                        )}

                        <div>
                            <InputLabel htmlFor="penalty_description" value="Penalty description" />
                            <TextArea
                                id="penalty_description"
                                value={data.penalty_description ?? ''}
                                onChange={(e) => setData('penalty_description', e.target.value)}
                                className="mt-1 block w-full"
                                rows={2}
                            />
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Verification</h2>
                    </div>
                    <div className="card-body space-y-3">
                        <SelectInput
                            value={data.verification_status}
                            onChange={(e) => setData('verification_status', e.target.value)}
                            className="block w-full"
                            aria-label="Verification status"
                        >
                            {options.verificationStatuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </SelectInput>
                        <p className="text-xs text-gray-400">
                            Mark this “verified” only once every date, threshold and penalty above has been confirmed
                            against the regulator’s primary document. Anything else is excluded from generated
                            submissions.
                        </p>

                        <PrimaryButton disabled={processing} className="w-full justify-center">
                            {submitLabel}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    );
}
