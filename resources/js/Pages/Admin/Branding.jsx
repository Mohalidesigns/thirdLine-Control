import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import TextArea from '@/Components/TextArea';
import TextInput from '@/Components/TextInput';
import { Head, useForm } from '@inertiajs/react';

function ColourField({ label, value, onChange, error, hint }) {
    return (
        <div>
            <InputLabel value={label} />
            <div className="mt-1 flex items-center gap-2">
                <input
                    type="color"
                    className="h-9 w-12 cursor-pointer rounded border border-gray-300"
                    value={value || '#0B1F3A'}
                    onChange={(e) => onChange(e.target.value)}
                />
                <TextInput
                    className="w-32 font-mono text-xs"
                    value={value ?? ''}
                    placeholder="#0B1F3A"
                    onChange={(e) => onChange(e.target.value || null)}
                />
            </div>
            {hint && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{hint}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function FileField({ label, current, onChange, error, accept, hint }) {
    return (
        <div>
            <InputLabel value={label} />
            {current && (
                <img src={current} alt="" className="my-1 max-h-10 rounded border border-gray-200 bg-gray-50 object-contain p-1" />
            )}
            <input
                type="file"
                accept={accept}
                className="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--color-primary)] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
                onChange={(e) => onChange(e.target.files[0] ?? null)}
            />
            {hint && <p className="mt-1 text-xs text-[var(--color-text-secondary)]">{hint}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

export default function Branding({ branding }) {
    const { data, setData, post, processing, errors } = useForm({
        primary_colour: branding?.primary_colour ?? null,
        accent_colour: branding?.accent_colour ?? null,
        product_name: branding?.product_name ?? '',
        support_email: branding?.support_email ?? '',
        support_phone: branding?.support_phone ?? '',
        report_header_html: branding?.report_header_html ?? '',
        report_footer_html: branding?.report_footer_html ?? '',
        email_from_name: branding?.email_from_name ?? '',
        logo: null,
        logo_dark: null,
        favicon: null,
        login_background: null,
    });

    return (
        <AuthenticatedLayout header="Branding">
            <Head title="Branding" />

            <PageHeader
                title="Branding & white-label"
                subtitle="Your identity across the app shell, login page, PDF reports, and email. AEGIS defaults apply wherever nothing is set."
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('admin.branding.update'), { preserveScroll: true, forceFormData: true });
                }}
                className="max-w-3xl space-y-6"
            >
                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">Identity</h3>
                    </div>
                    <div className="card-body grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Product name" />
                            <TextInput
                                value={data.product_name}
                                placeholder="SecondLine"
                                onChange={(e) => setData('product_name', e.target.value)}
                            />
                            <InputError message={errors.product_name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Email sender name" />
                            <TextInput
                                value={data.email_from_name}
                                placeholder="First Bank GRC"
                                onChange={(e) => setData('email_from_name', e.target.value)}
                            />
                            <InputError message={errors.email_from_name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Support email" />
                            <TextInput
                                type="email"
                                value={data.support_email}
                                onChange={(e) => setData('support_email', e.target.value)}
                            />
                            <InputError message={errors.support_email} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Support phone" />
                            <TextInput value={data.support_phone} onChange={(e) => setData('support_phone', e.target.value)} />
                            <InputError message={errors.support_phone} className="mt-1" />
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">Colours</h3>
                    </div>
                    <div className="card-body grid gap-4 sm:grid-cols-2">
                        <ColourField
                            label="Primary colour"
                            value={data.primary_colour}
                            onChange={(v) => setData('primary_colour', v)}
                            error={errors.primary_colour}
                            hint="Carries white text — must meet 4.5:1 contrast or it will be rejected."
                        />
                        <ColourField
                            label="Accent colour"
                            value={data.accent_colour}
                            onChange={(v) => setData('accent_colour', v)}
                            error={errors.accent_colour}
                        />
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">Images</h3>
                    </div>
                    <div className="card-body grid gap-4 sm:grid-cols-2">
                        <FileField
                            label="Logo (light backgrounds)"
                            current={branding?.logo_url}
                            accept="image/*"
                            onChange={(f) => setData('logo', f)}
                            error={errors.logo}
                        />
                        <FileField
                            label="Logo (dark backgrounds)"
                            current={branding?.logo_dark_url}
                            accept="image/*"
                            onChange={(f) => setData('logo_dark', f)}
                            error={errors.logo_dark}
                            hint="Used on the navy sidebar and login page."
                        />
                        <FileField
                            label="Favicon"
                            current={branding?.favicon_url}
                            accept=".png,.ico,.svg"
                            onChange={(f) => setData('favicon', f)}
                            error={errors.favicon}
                        />
                        <FileField
                            label="Login background"
                            current={branding?.login_background_url}
                            accept="image/*"
                            onChange={(f) => setData('login_background', f)}
                            error={errors.login_background}
                        />
                    </div>
                </div>

                <div className="card">
                    <div className="card-header">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">PDF reports</h3>
                    </div>
                    <div className="card-body grid gap-4">
                        <div>
                            <InputLabel value="Report header (HTML)" />
                            <TextArea
                                rows={3}
                                value={data.report_header_html}
                                onChange={(e) => setData('report_header_html', e.target.value)}
                            />
                            <InputError message={errors.report_header_html} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Report footer (HTML)" />
                            <TextArea
                                rows={2}
                                value={data.report_footer_html}
                                onChange={(e) => setData('report_footer_html', e.target.value)}
                            />
                            <InputError message={errors.report_footer_html} className="mt-1" />
                        </div>
                    </div>
                </div>

                <PrimaryButton disabled={processing}>Save branding</PrimaryButton>
            </form>
        </AuthenticatedLayout>
    );
}
