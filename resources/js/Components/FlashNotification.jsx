import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

const STYLES = {
    success: 'border-[var(--color-success)] bg-green-50 text-green-800',
    error: 'border-[var(--color-error)] bg-red-50 text-red-800',
    warning: 'border-[var(--color-warning)] bg-orange-50 text-orange-800',
    info: 'border-[var(--color-info)] bg-teal-50 text-teal-800',
};

const ICONS = {
    success: CheckCircle2,
    error: XCircle,
    warning: AlertTriangle,
    info: Info,
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

    const Icon = ICONS[visible.type];

    return (
        <div className="fixed bottom-6 right-6 z-[60] animate-slide-in">
            <div className={`flex items-center gap-3 rounded-xl border-l-4 px-4 py-3 shadow-lg ${STYLES[visible.type]}`}>
                <Icon className="h-5 w-5 shrink-0" strokeWidth={1.8} aria-hidden="true" />
                <p className="text-sm font-medium">{visible.message}</p>
                <button
                    type="button"
                    onClick={() => setVisible(null)}
                    className="text-current opacity-60 hover:opacity-100"
                    aria-label="Dismiss"
                >
                    <X className="h-4 w-4" strokeWidth={2} />
                </button>
            </div>
        </div>
    );
}
