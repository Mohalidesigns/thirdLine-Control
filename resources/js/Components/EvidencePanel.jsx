import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import { formatDateTime } from '@/utils';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

const PII_CATEGORIES = [
    'Account numbers', 'BVN', 'Identity documents', 'Names & addresses',
    'Phone / email', 'Transaction data', 'Biometric data', 'Other',
];

/**
 * Evidence list + upload for any linkable record. Upload is blocked until
 * the personal-data declaration is answered (FR-9.2), with the data
 * minimisation prompt shown before selection (FR-9.4).
 */
export default function EvidencePanel({ linkedType, linkedId, evidence = [], canUpload = true }) {
    const [showUpload, setShowUpload] = useState(false);

    const form = useForm({
        file: null,
        linked_type: linkedType,
        linked_id: linkedId,
        contains_personal_data: '',
        personal_data_categories: [],
        classification: 'Internal',
    });

    const toggleCategory = (category) => {
        form.setData(
            'personal_data_categories',
            form.data.personal_data_categories.includes(category)
                ? form.data.personal_data_categories.filter((c) => c !== category)
                : [...form.data.personal_data_categories, category],
        );
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(route('evidence.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setShowUpload(false);
            },
        });
    };

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="text-sm font-semibold">Evidence ({evidence.length})</h3>
                {canUpload && (
                    <button type="button" className="text-sm text-[var(--color-primary)] hover:underline" onClick={() => setShowUpload(true)}>
                        + Upload
                    </button>
                )}
            </div>
            <div className="card-body">
                {evidence.length === 0 ? (
                    <p className="text-sm text-gray-400">No evidence attached.</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {evidence.map((item) => (
                            <li key={item.id} className="flex items-center justify-between gap-3 py-2.5">
                                <div className="min-w-0">
                                    <a
                                        href={item.disposed_at ? undefined : route('evidence.download', item.id)}
                                        className={`block truncate text-sm font-medium ${item.disposed_at ? 'text-gray-400 line-through' : 'text-[var(--color-primary)] hover:underline'}`}
                                    >
                                        {item.file_name}
                                    </a>
                                    <p className="text-xs text-gray-400">
                                        {item.uploader?.name ?? '—'} · {formatDateTime(item.uploaded_at)} · {Math.round((item.file_size ?? 0) / 1024)} KB
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-1.5">
                                    {item.contains_personal_data && <span className="badge badge-high">PII</span>}
                                    {item.legal_hold && <span className="badge badge-critical">Legal hold</span>}
                                    <span className="badge badge-status-draft">{item.classification}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Modal show={showUpload} onClose={() => setShowUpload(false)} title="Upload evidence" maxWidth="lg">
                <form onSubmit={submit} className="space-y-4">
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        <strong>Data minimisation:</strong> redact account numbers, BVN, and identity data unless
                        they are essential to the test conclusion. Evidence containing customer personal data is
                        subject to NDPA retention and access controls.
                    </div>

                    <div>
                        <InputLabel value="File" required />
                        <input
                            type="file"
                            className="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--color-primary)] file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-[var(--color-primary-light)]"
                            onChange={(e) => form.setData('file', e.target.files[0])}
                        />
                        <InputError message={form.errors.file} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Does this evidence contain customer personal data?" required />
                        <SelectInput
                            value={form.data.contains_personal_data}
                            onChange={(e) => form.setData('contains_personal_data', e.target.value)}
                        >
                            <option value="">— You must answer before uploading —</option>
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </SelectInput>
                        <InputError message={form.errors.contains_personal_data} className="mt-1" />
                    </div>

                    {form.data.contains_personal_data === '1' && (
                        <div>
                            <InputLabel value="Data categories present" required />
                            <div className="mt-1 grid grid-cols-2 gap-1.5">
                                {PII_CATEGORIES.map((category) => (
                                    <label key={category} className="flex items-center gap-2 text-sm text-gray-600">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                            checked={form.data.personal_data_categories.includes(category)}
                                            onChange={() => toggleCategory(category)}
                                        />
                                        {category}
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.personal_data_categories} className="mt-1" />
                        </div>
                    )}

                    <div>
                        <InputLabel value="Classification" />
                        <SelectInput value={form.data.classification} onChange={(e) => form.setData('classification', e.target.value)}>
                            {['Public', 'Internal', 'Confidential', 'Restricted'].map((level) => (
                                <option key={level} value={level}>{level}</option>
                            ))}
                        </SelectInput>
                    </div>

                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={() => setShowUpload(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing || !form.data.file || form.data.contains_personal_data === ''}>
                            Upload
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
