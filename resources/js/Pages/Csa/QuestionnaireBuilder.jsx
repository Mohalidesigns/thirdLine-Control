import Checkbox from '@/Components/Checkbox';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';

const RESPONSE_TYPES = [
    ['yes_no', 'Yes / No'],
    ['yes_no_na', 'Yes / No / N-A'],
    ['scale_1_5', 'Scale 1–5'],
    ['maturity_0_5', 'Maturity 0–5'],
    ['single_select', 'Single select'],
    ['multi_select', 'Multi select'],
    ['free_text', 'Free text'],
    ['numeric', 'Numeric'],
    ['date', 'Date'],
];

/** Draft-mode questionnaire builder with sections, conditions and triggers. */
export default function QuestionnaireBuilder({ campaign, questionnaire, controls = [], requirements = [] }) {
    const { data, setData, put, processing } = useForm({
        questions: (questionnaire.questions ?? []).map((q) => ({
            id: q.id,
            section: q.section ?? '',
            question_text: q.question_text,
            help_text: q.help_text ?? '',
            response_type: q.response_type,
            options: q.options ?? [],
            is_required: !!q.is_required,
            evidence_required: !!q.evidence_required,
            weight: q.weight ?? 1,
            control_id: q.control_id,
            requirement_id: q.requirement_id,
            triggers_exception_on: q.triggers_exception_on,
            condition: q.condition,
        })),
    });

    const setQuestion = (index, patch) =>
        setData('questions', data.questions.map((q, i) => (i === index ? { ...q, ...patch } : q)));

    const addQuestion = () =>
        setData('questions', [
            ...data.questions,
            { section: '', question_text: '', help_text: '', response_type: 'yes_no', options: [], is_required: true, evidence_required: false, weight: 1, control_id: null, requirement_id: null, triggers_exception_on: null, condition: null },
        ]);

    const save = () => put(route('csa-campaigns.questions', campaign.id), { preserveScroll: true });

    return (
        <div className="card mb-6">
            <div className="card-header flex items-center justify-between">
                <h3 className="text-sm font-semibold">Questionnaire builder</h3>
                <div className="flex gap-2">
                    <button type="button" className="btn-secondary !py-1.5 text-xs" onClick={addQuestion}>+ Add question</button>
                    <button type="button" className="btn-primary !py-1.5 text-xs" onClick={save} disabled={processing || data.questions.length === 0}>
                        Save questionnaire
                    </button>
                </div>
            </div>
            <div className="card-body space-y-4">
                {data.questions.length === 0 && (
                    <p className="text-sm text-[var(--color-text-secondary)]">No questions yet — add the first one.</p>
                )}
                {data.questions.map((q, i) => (
                    <div key={q.id ?? `new-${i}`} className="rounded-xl border border-gray-200 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <span className="mt-2 font-mono text-xs text-gray-400">Q{i + 1}</span>
                            <div className="flex-1 space-y-3">
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr_1fr]">
                                    <TextInput
                                        value={q.question_text}
                                        placeholder="Question text…"
                                        onChange={(e) => setQuestion(i, { question_text: e.target.value })}
                                    />
                                    <SelectInput value={q.response_type} onChange={(e) => setQuestion(i, { response_type: e.target.value })}>
                                        {RESPONSE_TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </SelectInput>
                                    <TextInput value={q.section} placeholder="Section (optional)" onChange={(e) => setQuestion(i, { section: e.target.value })} />
                                </div>

                                {['single_select', 'multi_select'].includes(q.response_type) && (
                                    <TextInput
                                        value={(q.options ?? []).join(', ')}
                                        placeholder="Options, comma-separated"
                                        onChange={(e) => setQuestion(i, { options: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) })}
                                    />
                                )}

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <SelectInput value={q.control_id ?? ''} onChange={(e) => setQuestion(i, { control_id: e.target.value || null })}>
                                        <option value="">Link control (optional)…</option>
                                        {controls.map((c) => <option key={c.id} value={c.id}>{c.control_ref} — {c.title}</option>)}
                                    </SelectInput>
                                    <SelectInput value={q.requirement_id ?? ''} onChange={(e) => setQuestion(i, { requirement_id: e.target.value || null })}>
                                        <option value="">Link requirement (optional)…</option>
                                        {requirements.map((r) => <option key={r.id} value={r.id}>{r.ref_code} — {r.title}</option>)}
                                    </SelectInput>
                                    <TextInput
                                        type="number" step="0.5" min="0"
                                        value={q.weight}
                                        placeholder="Weight"
                                        onChange={(e) => setQuestion(i, { weight: e.target.value })}
                                    />
                                </div>

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <p className="text-xs font-medium text-[var(--color-text-secondary)]">Raise an exception when the answer is…</p>
                                        <TextInput
                                            value={(q.triggers_exception_on?.in ?? []).join(', ')}
                                            placeholder="e.g. No (comma-separated, blank = never)"
                                            onChange={(e) => {
                                                const values = e.target.value.split(',').map((s) => s.trim()).filter(Boolean);
                                                setQuestion(i, { triggers_exception_on: values.length ? { in: values, severity: q.triggers_exception_on?.severity ?? 'Medium' } : null });
                                            }}
                                        />
                                    </div>
                                    <div>
                                        <p className="text-xs font-medium text-[var(--color-text-secondary)]">Show only when…</p>
                                        <div className="flex gap-2">
                                            <SelectInput
                                                value={q.condition?.question_id ?? ''}
                                                onChange={(e) => setQuestion(i, { condition: e.target.value ? { question_id: Number(e.target.value), in: q.condition?.in ?? [] } : null })}
                                            >
                                                <option value="">Always shown</option>
                                                {data.questions.filter((_, j) => j !== i && data.questions[j].id).map((other, j) => (
                                                    <option key={other.id} value={other.id}>Q{data.questions.indexOf(other) + 1} answered…</option>
                                                ))}
                                            </SelectInput>
                                            {q.condition?.question_id && (
                                                <TextInput
                                                    value={(q.condition?.in ?? []).join(', ')}
                                                    placeholder="values"
                                                    onChange={(e) => setQuestion(i, { condition: { ...q.condition, in: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) } })}
                                                />
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-4 text-sm">
                                    <label className="flex items-center gap-2">
                                        <Checkbox checked={q.is_required} onChange={(e) => setQuestion(i, { is_required: e.target.checked })} />
                                        Required
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <Checkbox checked={q.evidence_required} onChange={(e) => setQuestion(i, { evidence_required: e.target.checked })} />
                                        Evidence required
                                    </label>
                                    <button
                                        type="button"
                                        className="ml-auto text-xs text-[var(--color-error)]"
                                        onClick={() => setData('questions', data.questions.filter((_, j) => j !== i))}
                                    >
                                        Remove question
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
