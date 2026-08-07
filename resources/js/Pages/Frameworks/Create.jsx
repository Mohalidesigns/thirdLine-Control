import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Create({ categories = [], verificationStatuses = [] }) {
    const form = useForm({
        code: '',
        name: '',
        issuing_body: '',
        jurisdiction: 'NG',
        version: '1.0.0',
        category: 'internal_control',
        effective_from: '',
        effective_to: '',
        is_certifiable: false,
        source_url: '',
        // R10: nothing claims to be verified until a person has checked it.
        verification_status: 'draft',
        description: '',
        is_active: true,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('frameworks.store'));
    };

    const importPack = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        const data = new FormData();
        data.append('pack', file);
        form.transform(() => data).post(route('frameworks.import'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header="Add framework">
            <Head title="Add framework" />

            <PageHeader
                breadcrumbs={[{ label: 'Frameworks', href: route('frameworks.index') }, { label: 'New' }]}
                title="Add a framework"
                subtitle="Define your own framework, or import one in content-pack JSON format"
                actions={
                    <Link href={route('frameworks.index')} className="btn-secondary">
                        Cancel
                    </Link>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <form onSubmit={submit} className="card lg:col-span-2">
                    <div className="card-body grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="code" value="Code" />
                            <TextInput
                                id="code"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                className="mt-1 block w-full"
                                placeholder="e.g. CBN-CG-2023"
                                required
                            />
                            <InputError message={form.errors.code} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="version" value="Version" />
                            <TextInput
                                id="version"
                                value={form.data.version}
                                onChange={(e) => form.setData('version', e.target.value)}
                                className="mt-1 block w-full"
                                required
                            />
                            <InputError message={form.errors.version} className="mt-1" />
                        </div>

                        <div className="sm:col-span-2">
                            <InputLabel htmlFor="name" value="Name" />
                            <TextInput
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="mt-1 block w-full"
                                required
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="issuing_body" value="Issuing body" />
                            <TextInput
                                id="issuing_body"
                                value={form.data.issuing_body}
                                onChange={(e) => form.setData('issuing_body', e.target.value)}
                                className="mt-1 block w-full"
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="jurisdiction" value="Jurisdiction" />
                            <TextInput
                                id="jurisdiction"
                                value={form.data.jurisdiction}
                                onChange={(e) => form.setData('jurisdiction', e.target.value)}
                                className="mt-1 block w-full"
                                placeholder="NG, GH, KE, ZA or INTL"
                                required
                            />
                            <InputError message={form.errors.jurisdiction} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="category" value="Category" />
                            <SelectInput
                                id="category"
                                value={form.data.category}
                                onChange={(e) => form.setData('category', e.target.value)}
                                className="mt-1 block w-full"
                            >
                                {categories.map((category) => (
                                    <option key={category} value={category}>
                                        {category.replace('_', ' ')}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>

                        <div>
                            <InputLabel htmlFor="verification_status" value="Verification status" />
                            <SelectInput
                                id="verification_status"
                                value={form.data.verification_status}
                                onChange={(e) => form.setData('verification_status', e.target.value)}
                                className="mt-1 block w-full"
                            >
                                {verificationStatuses.map((status) => (
                                    <option key={status} value={status}>
                                        {status}
                                    </option>
                                ))}
                            </SelectInput>
                            <p className="mt-1 text-xs text-gray-400">
                                Only “verified” content may appear in a generated regulatory submission.
                            </p>
                        </div>

                        <div>
                            <InputLabel htmlFor="effective_from" value="Effective from" />
                            <TextInput
                                id="effective_from"
                                type="date"
                                value={form.data.effective_from}
                                onChange={(e) => form.setData('effective_from', e.target.value)}
                                className="mt-1 block w-full"
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="source_url" value="Source URL" />
                            <TextInput
                                id="source_url"
                                type="url"
                                value={form.data.source_url}
                                onChange={(e) => form.setData('source_url', e.target.value)}
                                className="mt-1 block w-full"
                            />
                            <InputError message={form.errors.source_url} className="mt-1" />
                        </div>

                        <div className="sm:col-span-2">
                            <InputLabel htmlFor="description" value="Description" />
                            <TextArea
                                id="description"
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                className="mt-1 block w-full"
                                rows={3}
                            />
                        </div>

                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_certifiable}
                                onChange={(e) => form.setData('is_certifiable', e.target.checked)}
                            />
                            Certifiable standard (an accredited body can certify against it)
                        </label>
                    </div>

                    <div className="card-header flex justify-end">
                        <PrimaryButton disabled={form.processing}>Create framework</PrimaryButton>
                    </div>
                </form>

                <div className="card h-fit">
                    <div className="card-header">
                        <h2 className="text-sm font-semibold">Import a pack</h2>
                    </div>
                    <div className="card-body space-y-3">
                        <p className="text-sm text-gray-500">
                            Upload a framework in content-pack JSON format. Requirements are matched on reference code,
                            so re-importing an updated pack amends in place rather than duplicating.
                        </p>
                        <input type="file" accept="application/json,.json" onChange={importPack} className="text-sm" />
                        <InputError message={form.errors.pack} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
