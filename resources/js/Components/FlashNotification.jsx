import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const STYLES = {
    success: 'border-[var(--color-success)] bg-green-50 text-green-800',
    error: 'border-[var(--color-error)] bg-red-50 text-red-800',
    warning: 'border-[var(--color-warning)] bg-orange-50 text-orange-800',
    info: 'border-[var(--color-info)] bg-teal-50 text-teal-800',
};

export default function FlashNotification() {
    const { flash } = usePage().props;
    const [visible, setVisible] = useState(null);

    useEffect(() => {
        const type = ['success', 'error', 'warning', 'info'].find((key) => flash?.[key]);
        if (type) {
            setVisible({ type, message: flash[type] });
            const timer = setTimeout(() => setVisible(null), 5000);
            return () => clearTimeout(timer);
        }
    }, [flash]);

    if (!visible) return null;

    return (
        <div className="fixed bottom-6 right-6 z-[60] animate-slide-in">
            <div className={`flex items-center gap-3 rounded-xl border-l-4 px-4 py-3 shadow-lg ${STYLES[visible.type]}`}>
                <p className="text-sm font-medium">{visible.message}</p>
                <button
                    type="button"
                    onClick={() => setVisible(null)}
                    className="text-current opacity-60 hover:opacity-100"
                >
                    ✕
                </button>
            </div>
        </div>
    );
}
