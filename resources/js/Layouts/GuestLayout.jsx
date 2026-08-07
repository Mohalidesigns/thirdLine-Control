import { usePage } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    const branding = usePage().props.branding;

    const backgroundStyle = branding?.login_background_url
        ? {
              backgroundImage: `linear-gradient(rgba(11, 31, 58, 0.85), rgba(11, 31, 58, 0.85)), url(${branding.login_background_url})`,
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
                    <>
                        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--color-accent)] text-lg font-bold text-[var(--color-primary)]">
                            SL
                        </div>
                        <div className="leading-tight text-white">
                            <p className="text-xl font-bold tracking-wide">SecondLine</p>
                            <p className="text-[11px] uppercase tracking-widest text-white/60">Atheris Control Solution</p>
                        </div>
                    </>
                )}
                {(branding?.logo_dark_url || branding?.logo_url) && branding?.product_name && (
                    <p className="text-xl font-bold tracking-wide text-white">{branding.product_name}</p>
                )}
            </div>

            <div className="w-full max-w-md rounded-xl bg-white p-8 shadow-xl">{children}</div>

            <p className="mt-6 text-xs text-white/40">
                © {new Date().getFullYear()} {branding?.product_name ?? 'Atheris Limited — Second line of defence'}
            </p>
        </div>
    );
}
