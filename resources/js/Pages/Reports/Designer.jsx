import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const NEEDS_SOURCE = ['chart', 'table'];
const NEEDS_BODY = ['narrative', 'appendix'];

/**
 * The report designer: an ordered list of sections, each typed, some of them
 * bound to a registered dataset.
 *
 * Narrative bodies take {{ token }} interpolation — {{ period }},
 * {{ entity }}, {{ tenant }}, {{ generated_at }}, and {{ metric:source_key }}
 * for a live figure — so a paragraph cannot quote a number the rest of the
 * document contradicts.
 */
export default function Designer({
    definition,
    sectionTypes = [],
    types = [],
    formats = [],
    confidentialities = [],
    sources = [],
    parameterOptions = {},
}) {
    const [sections, setSections] = useState(() => definition?.sections ?? [
        { type: 'cover', title: '{{ tenant }}', subtitle: '{{ period }}' },
    ]);

    const form = useForm({
        code: definition?.code ?? '',
        name: definition?.name ?? '',
        description: definition?.description ?? '',
        report_type: definition?.report_type ?? 'management',
        confidentiality: definition?.confidentiality ?? 'Internal',
        output_formats: definition?.output_formats ?? ['pdf'],
        parameters: definition?.parameters ?? [
            { key: 'period', label: 'Period', type: 'period', default: 'current_quarter' },
            { key: 'entity_id', label: 'Entity', type: 'entity' },
        ],
        styling: definition?.styling ?? { orientation: 'portrait', paper: 'a4' },
        is_active: definition?.is_active ?? true,
    });

    const patch = (index, changes) => setSections((current) =>
        current.map((section, i) => (i === index ? { ...section, ...changes } : section)));

    const move = (from, to) => {
        if (to < 0 || to >= sections.length) {
            return;
        }

        setSections((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);
            next.splice(to, 0, moved);

            return next;
        });
    };

    const submit = (event) => {
        event.preventDefault();

        const payload = { ...form.data, sections };

        if (definition?.id) {
            router.put(route('reports.definitions.update', definition.id), payload, { preserveScroll: true });
        } else {
            router.post(route('reports.definitions.store'), payload);
        }
    };

    return (
        <AuthenticatedLayout header={definition ? `Design ${definition.name}` : 'New report'}>
            <Head title={definition ? `Design ${definition.name}` : 'New report'} />

            <form onSubmit={submit}>
                <PageHeader
                    title={definition ? `Design ${definition.name}` : 'New report'}
                    subtitle="Sections render in this order into every output format."
                    breadcrumbs={[{ label: 'Report library', href: route('reports.library') }, { label: 'Designer' }]}
                    actions={
                        <>
                            <Link href={route('reports.library')} className="btn-secondary">Cancel</Link>
                            <button type="submit" className="btn-primary" disabled={form.processing}>
                                Save
                            </button>
                        </>
                    }
                />

                {definition?.approved_at && (
                    <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                        This version is approved. Changing the sections, formats or classification withdraws that
                        approval and raises the version number.
                    </p>
                )}

                <section className="card mb-4">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Report</h2>
                    </div>
                    <div className="card-body grid grid-cols-1 gap-4 md:grid-cols-3">
                        <Field label="Code" error={form.errors.code}>
                            <input
                                className="form-input"
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                                placeholder="BOARD-PACK"
                            />
                        </Field>
                        <Field label="Name" error={form.errors.name}>
                            <input
                                className="form-input"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field label="Type">
                            <select
                                className="form-select"
                                value={form.data.report_type}
                                onChange={(event) => form.setData('report_type', event.target.value)}
                            >
                                {types.map((type) => (
                                    <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Classification" hint="Drives who a scheduled copy may be sent to.">
                            <select
                                className="form-select"
                                value={form.data.confidentiality}
                                onChange={(event) => form.setData('confidentiality', event.target.value)}
                            >
                                {confidentialities.map((level) => (
                                    <option key={level} value={level}>{level}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Output formats" error={form.errors.output_formats} className="md:col-span-2">
                            <div className="flex flex-wrap gap-3 pt-1">
                                {formats.map((format) => (
                                    <label key={format} className="flex items-center gap-1.5 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={form.data.output_formats.includes(format)}
                                            onChange={(event) => form.setData(
                                                'output_formats',
                                                event.target.checked
                                                    ? [...form.data.output_formats, format]
                                                    : form.data.output_formats.filter((item) => item !== format),
                                            )}
                                        />
                                        {format.toUpperCase()}
                                    </label>
                                ))}
                            </div>
                        </Field>
                        <Field label="Description" className="md:col-span-3">
                            <input
                                className="form-input"
                                value={form.data.description ?? ''}
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                        </Field>
                    </div>
                </section>

                <section className="card">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Sections</h2>
                        <div className="flex flex-wrap gap-1">
                            {sectionTypes.map((type) => (
                                <button
                                    key={type}
                                    type="button"
                                    onClick={() => setSections((current) => [...current, { type, title: '' }])}
                                    className="rounded-md border border-gray-200 px-2 py-1 text-xs hover:border-[var(--color-primary)]"
                                >
                                    + {type.replace(/_/g, ' ')}
                                </button>
                            ))}
                        </div>
                    </div>

                    <ol className="card-body space-y-3">
                        {sections.map((section, index) => (
                            <li key={index} className="rounded-lg border border-gray-100 p-3">
                                <div className="mb-2 flex items-center gap-2">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        {index + 1}. {section.type.replace(/_/g, ' ')}
                                    </span>
                                    <div className="ms-auto flex items-center gap-2 text-xs">
                                        <button type="button" onClick={() => move(index, index - 1)} aria-label="Move up">↑</button>
                                        <button type="button" onClick={() => move(index, index + 1)} aria-label="Move down">↓</button>
                                        <button
                                            type="button"
                                            className="text-[var(--color-error)]"
                                            onClick={() => setSections((current) => current.filter((_, i) => i !== index))}
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>

                                {section.type !== 'page_break' && (
                                    <input
                                        className="form-input mb-2"
                                        value={section.title ?? ''}
                                        onChange={(event) => patch(index, { title: event.target.value })}
                                        placeholder="Section title"
                                        aria-label={`Section ${index + 1} title`}
                                    />
                                )}

                                {NEEDS_SOURCE.includes(section.type) && (
                                    <select
                                        className="form-select"
                                        value={section.source ?? ''}
                                        onChange={(event) => patch(index, { source: event.target.value })}
                                        aria-label={`Section ${index + 1} dataset`}
                                    >
                                        <option value="">Choose a dataset…</option>
                                        {sources.map((source) => (
                                            <option key={source.key} value={source.key}>
                                                {source.group} — {source.label}
                                            </option>
                                        ))}
                                    </select>
                                )}

                                {NEEDS_BODY.includes(section.type) && (
                                    <>
                                        <textarea
                                            className="form-input"
                                            rows={4}
                                            value={section.body ?? ''}
                                            onChange={(event) => patch(index, { body: event.target.value })}
                                            aria-label={`Section ${index + 1} body`}
                                        />
                                        <p className="mt-1 text-[10px] text-gray-400">
                                            Variables: {'{{ period }}'} {'{{ entity }}'} {'{{ tenant }}'}{' '}
                                            {'{{ generated_at }}'} {'{{ owner }}'} {'{{ metric:open_exceptions }}'}
                                        </p>
                                    </>
                                )}

                                {section.type === 'signature_block' && (
                                    <input
                                        className="form-input"
                                        value={(section.signatories ?? []).map((one) => one.role ?? one).join(', ')}
                                        onChange={(event) => patch(index, {
                                            signatories: event.target.value
                                                .split(',')
                                                .map((role) => ({ role: role.trim() }))
                                                .filter((one) => one.role !== ''),
                                        })}
                                        placeholder="Chairman, MD/CEO, Company Secretary"
                                        aria-label={`Section ${index + 1} signatories`}
                                    />
                                )}
                            </li>
                        ))}
                    </ol>
                </section>
            </form>
        </AuthenticatedLayout>
    );
}

function Field({ label, hint, error, className = '', children }) {
    return (
        <div className={className}>
            <span className="form-label">{label}</span>
            {children}
            {hint && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{hint}</p>}
            {error && <p className="mt-1 text-xs text-[var(--color-error)]">{error}</p>}
        </div>
    );
}
