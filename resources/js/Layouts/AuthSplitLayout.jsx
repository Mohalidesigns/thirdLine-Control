import ApplicationLogo from '@/Components/ApplicationLogo';
import { usePage } from '@inertiajs/react';
import {
    AlertCircle,
    BarChart3,
    CalendarCheck,
    FileCheck2,
    Gavel,
    Globe,
    History,
    Lock,
    Route,
    ScrollText,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * The signed-out marketing panel. Three slides, because one static claim
 * undersells a product this wide — and because a dot row that does not
 * actually go anywhere is a lie told in pixels.
 */
const SLIDES = [
    {
        headline: 'Control with',
        emphasis: 'confidence.',
        blurb: 'Design, test and evidence your control environment in one place — built for the second line of defence.',
        points: [
            { icon: ShieldCheck, label: 'Risk-based control structure & testing' },
            { icon: CalendarCheck, label: 'Frequency compliance & spot checks' },
            { icon: BarChart3, label: 'Real-time analytics & dashboards' },
        ],
    },
    {
        headline: 'Every exception,',
        emphasis: 'closed out.',
        blurb: 'Route, escalate and remediate exceptions on a clock — with the compensating controls recorded alongside.',
        points: [
            { icon: AlertCircle, label: 'Exception manager & ageing' },
            { icon: Route, label: 'Automated routing & escalation matrix' },
            { icon: Gavel, label: 'Investigations & case management' },
        ],
    },
    {
        headline: 'Evidence that',
        emphasis: 'stands up.',
        blurb: 'Obligations, attestations and board reporting assembled from live control data — never re-keyed.',
        points: [
            { icon: ScrollText, label: 'Obligation register & compliance calendar' },
            { icon: FileCheck2, label: 'Board-ready reports & submission packs' },
            { icon: History, label: 'Immutable, append-only audit trail' },
        ],
    },
];

const ROTATE_MS = 8000;

/* Posture the product actually ships, not certifications it has not been
   awarded. Swap these for real marks once they are held. */
const ASSURANCES = [
    { icon: Lock, label: '256-bit TLS' },
    { icon: History, label: 'Audit-trailed access' },
    { icon: Globe, label: 'Data residency' },
];

/**
 * The signed-out shell: brand panel on the left, one column of form on the
 * right. Every screen in the signed-out flow — sign in, the MFA challenge,
 * the invite/reset screen, the forced first-login change — renders through
 * here so the flow does not change shape underneath the user mid sign-in.
 *
 * @param {string} title      The screen's heading.
 * @param {string} subtitle   One line under it.
 * @param {boolean} assurances  Set false on a screen where the security
 *                              footer would just be noise.
 */
export default function AuthSplitLayout({ title, subtitle, assurances = true, children }) {
    const branding = usePage().props.branding;
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);

    useEffect(() => {
        if (paused || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            return undefined;
        }

        const timer = window.setInterval(() => setActive((i) => (i + 1) % SLIDES.length), ROTATE_MS);

        return () => window.clearInterval(timer);
    }, [paused]);

    const slide = SLIDES[active];
    const productName = branding?.product_name ?? 'SecondLine';

    const panelStyle = branding?.login_background_url
        ? {
              backgroundImage: `linear-gradient(rgba(16, 34, 58, 0.92), rgba(16, 34, 58, 0.92)), url(${branding.login_background_url})`,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
          }
        : undefined;

    return (
        <div className="flex min-h-screen bg-white">
            {/* ── Marketing panel — desktop only. On a phone the form is the page. ── */}
            <div
                className="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-[var(--color-primary)] px-12 py-10 xl:px-16 lg:flex"
                style={panelStyle}
                onMouseEnter={() => setPaused(true)}
                onMouseLeave={() => setPaused(false)}
            >
                {!branding?.login_background_url && (
                    <svg aria-hidden="true" className="pointer-events-none absolute inset-0 h-full w-full opacity-[0.18]">
                        <defs>
                            <pattern id="auth-grid" width="48" height="48" patternUnits="userSpaceOnUse">
                                <path d="M48 0H0V48" fill="none" stroke="white" strokeWidth="0.5" />
                            </pattern>
                            <radialGradient id="auth-glow" cx="30%" cy="30%" r="70%">
                                <stop offset="0%" stopColor="white" stopOpacity="0.5" />
                                <stop offset="100%" stopColor="white" stopOpacity="0" />
                            </radialGradient>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#auth-grid)" />
                        <rect width="100%" height="100%" fill="url(#auth-glow)" />
                    </svg>
                )}

                <div className="relative">
                    {branding?.logo_dark_url || branding?.logo_url ? (
                        <img
                            src={branding.logo_dark_url ?? branding.logo_url}
                            alt={productName}
                            className="max-h-11 max-w-[220px] object-contain"
                        />
                    ) : (
                        <ApplicationLogo tone="light" size="sm" tagline="caps" />
                    )}
                </div>

                <div className="relative max-w-lg">
                    <h2 className="text-5xl font-bold leading-[1.1] tracking-tight text-white">
                        {slide.headline}
                        <br />
                        <span className="text-[var(--color-accent-light)]">{slide.emphasis}</span>
                    </h2>

                    <p className="mt-6 text-lg leading-relaxed text-white/70">{slide.blurb}</p>

                    <ul className="mt-10 space-y-5">
                        {slide.points.map(({ icon: Icon, label }) => (
                            <li key={label} className="flex items-center gap-4">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-inset ring-white/15">
                                    <Icon className="h-5 w-5 text-[var(--color-accent-light)]" aria-hidden="true" />
                                </span>
                                <span className="text-[15px] text-white/85">{label}</span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="relative flex items-end justify-between gap-6">
                    <p className="text-xs text-white/40">
                        © {new Date().getFullYear()} {productName} — Internal Control Solution. All rights reserved.
                    </p>
                    <div className="flex shrink-0 items-center gap-2">
                        {SLIDES.map((s, i) => (
                            <button
                                key={s.emphasis}
                                type="button"
                                onClick={() => setActive(i)}
                                aria-label={`Show ${s.headline} ${s.emphasis}`}
                                aria-current={i === active}
                                className={`h-2 rounded-full transition-all duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-primary)] ${
                                    i === active ? 'w-6 bg-[var(--color-accent)]' : 'w-2 bg-white/25 hover:bg-white/40'
                                }`}
                            />
                        ))}
                    </div>
                </div>
            </div>

            {/* ── Form panel ────────────────────────────────────────────────── */}
            <div className="flex w-full flex-col justify-center bg-[var(--color-bg)] px-6 py-12 sm:px-12 lg:w-1/2">
                <div className="mx-auto w-full max-w-[26rem]">
                    <div className="mb-8">
                        {branding?.logo_url || branding?.logo_dark_url ? (
                            <img
                                src={branding.logo_url ?? branding.logo_dark_url}
                                alt={productName}
                                className="max-h-12 max-w-[220px] object-contain"
                            />
                        ) : (
                            <ApplicationLogo tone="dark" size="md" tagline="plain" />
                        )}
                    </div>

                    <h1 className="text-3xl font-bold tracking-tight text-[var(--color-primary)]">{title}</h1>
                    {subtitle && <p className="mt-2 text-sm text-[var(--color-text-secondary)]">{subtitle}</p>}

                    {children}

                    {assurances && (
                        <div className="mt-10">
                            <div className="relative">
                                <div className="absolute inset-0 flex items-center">
                                    <div className="w-full border-t border-gray-200" />
                                </div>
                                <div className="relative flex justify-center">
                                    <span className="bg-[var(--color-bg)] px-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-text-secondary)]">
                                        Secure access
                                    </span>
                                </div>
                            </div>
                            {/* Dividers only once the row fits on one line — a wrapped
                                `divide-x` leaves a rule dangling in front of the orphan. */}
                            <ul className="mt-4 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 sm:gap-x-0 sm:divide-x sm:divide-gray-200">
                                {ASSURANCES.map(({ icon: Icon, label }) => (
                                    <li
                                        key={label}
                                        className="flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)] sm:px-3"
                                    >
                                        <Icon className="h-3.5 w-3.5" aria-hidden="true" />
                                        {label}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
