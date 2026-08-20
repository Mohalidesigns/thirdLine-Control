import { useCallback, useState } from 'react';

/**
 * Hover and keyboard-focus readout for a mark.
 *
 * A tooltip enhances, never gates: every value it shows is also reachable
 * through the table view on the frame, and keyboard focus shows exactly what
 * hover does. Hit areas are sized on the mark itself — a pinpoint target the
 * reader has to land on dead-centre is a defect, not a detail.
 */
export function useTooltip() {
    const [tip, setTip] = useState(null);

    const show = useCallback((event, content) => {
        const host = event.currentTarget.closest('[data-chart-host]');

        if (!host) {
            return;
        }

        const bounds = host.getBoundingClientRect();
        const mark = event.currentTarget.getBoundingClientRect();

        setTip({
            content,
            x: mark.left - bounds.left + mark.width / 2,
            y: mark.top - bounds.top,
        });
    }, []);

    const hide = useCallback(() => setTip(null), []);

    const handlers = useCallback(
        (content) => ({
            onMouseEnter: (event) => show(event, content),
            onFocus: (event) => show(event, content),
            onMouseLeave: hide,
            onBlur: hide,
            tabIndex: 0,
        }),
        [show, hide],
    );

    return { tip, handlers, hide };
}

export function TooltipLayer({ tip }) {
    if (!tip) {
        return null;
    }

    return (
        <div
            role="status"
            className="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full rounded-lg bg-[var(--color-primary)] px-2.5 py-1.5 text-xs text-white shadow-lg"
            style={{ left: tip.x, top: Math.max(tip.y - 6, 0) }}
        >
            {tip.content}
        </div>
    );
}

/** Every chart body sits in this so tooltip coordinates have an origin. */
export function ChartHost({ children, className = '' }) {
    return (
        <div data-chart-host className={`relative ${className}`}>
            {children}
        </div>
    );
}
