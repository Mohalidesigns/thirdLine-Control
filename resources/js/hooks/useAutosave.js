import { useCallback, useEffect, useRef } from 'react';

/**
 * Autosave form state to localStorage on change (15.2): a power cut must
 * not cost work. Returns [restore, clear] — call restore() once on mount
 * to get the saved draft (or null), clear() after a successful submit.
 */
export default function useAutosave(key, data) {
    const storageKey = `secondline-draft:${key}`;
    const first = useRef(true);

    useEffect(() => {
        // Skip the mount write so an untouched form never overwrites a draft.
        if (first.current) {
            first.current = false;
            return;
        }

        try {
            localStorage.setItem(storageKey, JSON.stringify({ savedAt: Date.now(), data }));
        } catch {
            // Storage full or unavailable — autosave is best-effort.
        }
    }, [storageKey, JSON.stringify(data)]);

    const restore = useCallback(() => {
        try {
            const raw = localStorage.getItem(storageKey);
            return raw ? JSON.parse(raw).data : null;
        } catch {
            return null;
        }
    }, [storageKey]);

    const clear = useCallback(() => {
        try {
            localStorage.removeItem(storageKey);
        } catch {
            // Nothing to do.
        }
    }, [storageKey]);

    return [restore, clear];
}
