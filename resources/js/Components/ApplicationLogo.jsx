/**
 * The SecondLine brand mark — concentric lines of defence closing on a
 * verified control.
 *
 * Drawn inline rather than shipped as a raster so it stays sharp at every
 * size, needs no asset pipeline, and carries no third-party font (the CSP
 * blocks external faces — see resources/css/app.css). Colours come from the
 * --logo-* tokens; a tenant that uploads its own logo replaces this whole
 * component at the layout level, it does not recolour it.
 */

const SIZES = {
    sm: { mark: 'h-8 w-8', word: 'text-lg', caps: 'text-[9px]', plain: 'text-[11px]', gap: 'gap-2.5' },
    md: { mark: 'h-10 w-10', word: 'text-2xl', caps: 'text-[10px]', plain: 'text-sm', gap: 'gap-3' },
    lg: { mark: 'h-14 w-14', word: 'text-3xl', caps: 'text-[11px]', plain: 'text-base', gap: 'gap-3.5' },
};

export function LogoMark({ className = '' }) {
    return (
        <svg viewBox="0 0 64 64" role="presentation" aria-hidden="true" className={className}>
            {/* Outermost line — broken, because the outer perimeter never closes on its own. */}
            <circle
                cx="32"
                cy="32"
                r="29.5"
                fill="none"
                stroke="var(--logo-ring-outer)"
                strokeWidth="2.2"
                strokeLinecap="round"
                strokeDasharray="138 48"
                transform="rotate(-118 32 32)"
            />
            <circle
                cx="32"
                cy="32"
                r="25"
                fill="none"
                stroke="var(--logo-ring-mid)"
                strokeWidth="2.2"
                strokeLinecap="round"
                strokeDasharray="116 41"
                transform="rotate(28 32 32)"
            />
            {/* Second line — the closed one. */}
            <circle cx="32" cy="32" r="19.5" fill="none" stroke="var(--logo-ring-inner)" strokeWidth="5.5" />
            <circle cx="32" cy="32" r="17" fill="var(--logo-core)" />
            <path
                d="M25 32.5 L30 37.5 L40 26"
                fill="none"
                stroke="var(--logo-check)"
                strokeWidth="4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

/**
 * @param {'light'|'dark'} tone   'light' = white type for navy surfaces, 'dark' = navy type for white ones.
 * @param {'sm'|'md'|'lg'} size
 * @param {false|'caps'|'plain'} tagline
 * @param {boolean} wordmark      false renders the mark alone (collapsed sidebar, favicons, avatars).
 */
export default function ApplicationLogo({
    tone = 'light',
    size = 'md',
    tagline = 'caps',
    taglineText = 'Internal Control Solution',
    wordmark = true,
    className = '',
}) {
    const s = SIZES[size] ?? SIZES.md;
    const onDark = tone === 'light';

    if (!wordmark) {
        return <LogoMark className={`${s.mark} shrink-0 ${className}`} />;
    }

    return (
        <span className={`inline-flex items-center ${s.gap} ${className}`}>
            <LogoMark className={`${s.mark} shrink-0`} />
            <span className="leading-none">
                <span className={`block ${s.word} tracking-tight`}>
                    <span className={`font-normal ${onDark ? 'text-white' : 'text-[var(--color-primary)]'}`}>second</span>
                    <span className={`font-bold ${onDark ? 'text-[var(--logo-line)]' : 'text-[var(--color-primary)]'}`}>line</span>
                </span>
                {tagline === 'caps' && (
                    <span
                        className={`mt-1 block ${s.caps} font-medium uppercase tracking-[0.18em] ${
                            onDark ? 'text-white/60' : 'text-[var(--navy-400)]'
                        }`}
                    >
                        {taglineText}
                    </span>
                )}
                {tagline === 'plain' && (
                    <span
                        className={`mt-1.5 block ${s.plain} font-medium ${
                            onDark ? 'text-white/70' : 'text-[var(--navy-500)]'
                        }`}
                    >
                        {taglineText}
                    </span>
                )}
            </span>
        </span>
    );
}
