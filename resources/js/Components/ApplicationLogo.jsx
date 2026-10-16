/**
 * The Fortis Internal Control brand mark.
 *
 * Served from public/images/brand as pre-sized PNGs (cut from the master
 * artwork in public/Fortis). The wordmark already carries the
 * "INTERNAL CONTROL" line, so no separate tagline is drawn. A tenant that
 * uploads its own logo replaces this whole component at the layout level.
 */

const LOGOS = {
    light: { full: '/images/brand/fortis-logo-light.png', mark: '/images/brand/fortis-mark-light.png' },
    dark: { full: '/images/brand/fortis-logo.png', mark: '/images/brand/fortis-mark.png' },
};

const SIZES = {
    sm: { full: 'h-11', mark: 'h-9 w-9' },
    md: { full: 'h-14', mark: 'h-10 w-10' },
    lg: { full: 'h-16', mark: 'h-14 w-14' },
};

export function LogoMark({ tone = 'light', className = '' }) {
    return <img src={(LOGOS[tone] ?? LOGOS.light).mark} alt="" aria-hidden="true" className={`object-contain ${className}`} />;
}

/**
 * @param {'light'|'dark'} tone  'light' = white type for navy surfaces, 'dark' = navy type for white ones.
 * @param {'sm'|'md'|'lg'} size
 * @param {boolean} wordmark     false renders the mark alone (collapsed sidebar, avatars).
 */
export default function ApplicationLogo({ tone = 'light', size = 'md', wordmark = true, className = '' }) {
    const s = SIZES[size] ?? SIZES.md;

    if (!wordmark) {
        return <LogoMark tone={tone} className={`${s.mark} shrink-0 ${className}`} />;
    }

    return (
        <img
            src={(LOGOS[tone] ?? LOGOS.light).full}
            alt="Fortis Internal Control"
            className={`${s.full} w-auto max-w-full object-contain ${className}`}
        />
    );
}
