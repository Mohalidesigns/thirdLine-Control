import TextInput from '@/Components/TextInput';
import { Eye, EyeOff, Lock } from 'lucide-react';
import { useState } from 'react';

/**
 * A password field with the lock affordance and a reveal toggle. Every
 * signed-out screen has at least one of these — sign-in, the MFA challenge,
 * the invite/reset flow and the forced first-login change — so the icon
 * padding and the toggle's accessible name live here once.
 *
 * The toggle is deliberately per-field rather than per-form: on the screens
 * with a confirmation box, revealing one should not reveal the other.
 */
export default function PasswordInput({ className = '', ...props }) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <Lock
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-text-secondary)]"
                aria-hidden="true"
            />
            <TextInput {...props} type={visible ? 'text' : 'password'} className={`py-2.5 pl-10 pr-10 ${className}`} />
            <button
                type="button"
                onClick={() => setVisible((v) => !v)}
                aria-label={visible ? 'Hide password' : 'Show password'}
                className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1.5 text-[var(--color-text-secondary)] transition-colors hover:text-[var(--color-primary)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--navy-400)]"
            >
                {visible ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
            </button>
        </div>
    );
}
