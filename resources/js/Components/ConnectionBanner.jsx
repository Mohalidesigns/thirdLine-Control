import { useEffect, useState } from 'react';

/**
 * Connection-quality banner (Phase 7.10): browser online/offline events
 * plus the Network Information API where available. Purely advisory —
 * the offline queue arrives in Phase 15.
 */
export default function ConnectionBanner() {
    const [state, setState] = useState('online'); // online | slow | offline

    useEffect(() => {
        const connection = navigator.connection;

        const evaluate = () => {
            if (!navigator.onLine) {
                setState('offline');
            } else if (connection && ['slow-2g', '2g'].includes(connection.effectiveType)) {
                setState('slow');
            } else {
                setState('online');
            }
        };

        evaluate();
        window.addEventListener('online', evaluate);
        window.addEventListener('offline', evaluate);
        connection?.addEventListener?.('change', evaluate);

        return () => {
            window.removeEventListener('online', evaluate);
            window.removeEventListener('offline', evaluate);
            connection?.removeEventListener?.('change', evaluate);
        };
    }, []);

    if (state === 'online') return null;

    return (
        <div
            className={`fixed inset-x-0 top-0 z-[70] px-4 py-1.5 text-center text-xs font-semibold text-white ${
                state === 'offline' ? 'bg-[var(--color-error)]' : 'bg-[var(--color-warning)]'
            }`}
            role="status"
        >
            {state === 'offline'
                ? 'You are offline — changes cannot be saved until the connection returns.'
                : 'Poor connection detected — pages may load slowly.'}
        </div>
    );
}
