import InputError from '@/Components/InputError';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * Public Speak-Up intake (11.4). The form collects no identifying field at
 * all, so there is nothing for a future change to start persisting.
 */
export default function Report({ concernTypes = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        concern_type: '',
        entity_hint: '',
        anonymous: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('whistleblowing.store'));
    };

    return (
        <GuestLayout>
            <Head title="Speak Up" />

            <div className="w-full max-w-2xl">
                <div className="mb-4 text-center">
                    <h1 className="text-2xl font-bold text-white">Speak Up</h1>
                    <p className="mt-1 text-sm text-white/70">
                        Raise a concern about fraud, corruption, a regulatory breach or misconduct.
                    </p>
                </div>

                <form onSubmit={submit} className="card">
                    <div className="card-body space-y-4">
                        <div className="rounded-lg bg-gray-50 p-4 text-sm text-[var(--color-text-secondary)]">
                            <p className="font-semibold text-[var(--color-text-primary)]">Your anonymity</p>
                            <p className="mt-1">
                                If you leave “Report anonymously” ticked, nothing that identifies you is recorded — no
                                name, no account, no address. You will be given a reference token once, and it is the
                                only way to follow the case or add to it. Keep it somewhere safe: it cannot be reissued.
                            </p>
                        </div>

                        <div>
                            <label className="form-label">
                                What is your concern about? <span className="text-red-500">*</span>
                            </label>
                            <input
                                className="form-input"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="A short summary"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <label className="form-label">Type of concern</label>
                            <select
                                className="form-select"
                                value={data.concern_type}
                                onChange={(e) => setData('concern_type', e.target.value)}
                            >
                                <option value="">Prefer not to say</option>
                                {concernTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">
                                Tell us what happened <span className="text-red-500">*</span>
                            </label>
                            <textarea
                                className="form-input"
                                rows={8}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="What happened, when, and who was involved. Please do not include your own name if you want to stay anonymous."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5"
                                checked={data.anonymous}
                                onChange={(e) => setData('anonymous', e.target.checked)}
                            />
                            <span>
                                Report anonymously
                                <span className="block text-xs text-gray-400">
                                    Untick only if you are happy for your identity to be recorded on the case.
                                </span>
                            </span>
                        </label>

                        <div className="flex items-center justify-between">
                            <Link href={route('whistleblowing.status')} className="text-sm text-[var(--color-primary)] hover:underline">
                                I already have a token
                            </Link>
                            <button type="submit" className="btn-primary" disabled={processing}>
                                {processing ? 'Submitting…' : 'Submit report'}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </GuestLayout>
    );
}
