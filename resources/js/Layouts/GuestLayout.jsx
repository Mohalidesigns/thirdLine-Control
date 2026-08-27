import ApplicationLogo from '@/Components/ApplicationLogo';
import ValidationNotification from '@/Components/ValidationNotification';
import { usePage } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    const branding = usePage().props.branding;

    const backgroundStyle = branding?.login_background_url
        ? {
              backgroundImage: `linear-gradient(rgba(26, 54, 93, 0.85), rgba(26, 54, 93, 0.85)), url(${branding.login_background_url})`,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
          }
        : undefined;

    return (
        <div
            className="flex min-h-screen flex-col items-center justify-center bg-[var(--color-primary)] px-4"
            style={backgroundStyle}
        >
            <div className="mb-6 flex items-center gap-3">
                {branding?.logo_dark_url || branding?.logo_url ? (
                    <img
                        src={branding.logo_dark_url ?? branding.logo_url}
                        alt={branding?.product_name ?? 'SecondLine'}
                        className="max-h-14 max-w-[220px] object-contain"
                    />
                ) : (
                    <ApplicationLogo tone="light" size="lg" tagline="caps" />
                )}
                {(branding?.logo_dark_url || branding?.logo_url) && branding?.product_name && (
                    <p className="text-xl font-bold tracking-wide text-white">{branding.product_name}</p>
                )}
            </div>

            <div className="w-full max-w-md rounded-xl bg-white p-8 shadow-xl">{children}</div>

            {/* A reporter checking their case token gets the same courtesy as
                a signed-in user: a refused submission says why. */}
            <ValidationNotification />

            <p className="mt-6 text-xs text-white/40">
                © {new Date().getFullYear()} {branding?.product_name ?? 'Atheris Limited — Second line of defence'}
            </p>
        </div>
    );
}
