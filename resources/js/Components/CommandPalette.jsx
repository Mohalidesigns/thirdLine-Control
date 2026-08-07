import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/**
 * Cmd/Ctrl-K global search (Phase 7.7). Debounced, keyboard navigable,
 * grouped by record type. Results come pre-scoped by tenant + permission.
 */
export default function CommandPalette() {
    const { features = [] } = usePage().props;
    const enabled = features.includes('global-search');

    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [groups, setGroups] = useState([]);
    const [active, setActive] = useState(0);
    const [loading, setLoading] = useState(false);
    const inputRef = useRef(null);
    const debounceRef = useRef(null);

    const flat = useMemo(
        () => groups.flatMap((group) => group.results.map((result) => ({ ...result, groupLabel: group.label }))),
        [groups],
    );

    const close = useCallback(() => {
        setOpen(false);
        setTerm('');
        setGroups([]);
        setActive(0);
    }, []);

    // Global hotkey.
    useEffect(() => {
        if (!enabled) return undefined;
        const onKey = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            }
            if (e.key === 'Escape') close();
        };
        const onOpenEvent = () => setOpen(true);
        window.addEventListener('keydown', onKey);
        window.addEventListener('open-command-palette', onOpenEvent);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('open-command-palette', onOpenEvent);
        };
    }, [enabled, close]);

    useEffect(() => {
        if (open) setTimeout(() => inputRef.current?.focus(), 50);
    }, [open]);

    // Debounced fetch.
    useEffect(() => {
        if (!open) return undefined;
        clearTimeout(debounceRef.current);
        if (term.trim().length < 2) {
            setGroups([]);
            return undefined;
        }
        debounceRef.current = setTimeout(() => {
            setLoading(true);
            fetch(`${route('search')}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : { groups: [] }))
                .then((json) => {
                    setGroups(json.groups ?? []);
                    setActive(0);
                })
                .finally(() => setLoading(false));
        }, 250);
        return () => clearTimeout(debounceRef.current);
    }, [term, open]);

    if (!enabled || !open) return null;

    const go = (result) => {
        close();
        router.visit(result.url);
    };

    const onInputKey = (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((current) => Math.min(current + 1, flat.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((current) => Math.max(current - 1, 0));
        } else if (e.key === 'Enter' && flat[active]) {
            e.preventDefault();
            go(flat[active]);
        }
    };

    let index = -1;

    return (
        <div className="fixed inset-0 z-[60] flex items-start justify-center px-4 pt-[12vh]" role="dialog" aria-modal="true">
            <div className="fixed inset-0 bg-black/40" onClick={close} />
            <div className="relative w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-2xl">
                <div className="flex items-center gap-2 border-b border-gray-100 px-4">
                    <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input
                        ref={inputRef}
                        type="text"
                        className="w-full border-0 py-3.5 text-sm focus:ring-0"
                        placeholder="Search controls, risks, exceptions, tests…"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        onKeyDown={onInputKey}
                    />
                    <kbd className="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] text-gray-400">ESC</kbd>
                </div>

                <div className="max-h-[50vh] overflow-y-auto">
                    {loading && <p className="px-4 py-3 text-sm text-[var(--color-text-secondary)]">Searching…</p>}

                    {!loading && term.trim().length >= 2 && groups.length === 0 && (
                        <p className="px-4 py-6 text-center text-sm text-[var(--color-text-secondary)]">No results for “{term}”.</p>
                    )}

                    {groups.map((group) => (
                        <div key={group.type} className="py-1">
                            <p className="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">
                                {group.label}
                                {group.total > group.results.length && ` — top ${group.results.length} of ${group.total}`}
                            </p>
                            {group.results.map((result) => {
                                index += 1;
                                const isActive = index === active;
                                const currentIndex = index;
                                return (
                                    <button
                                        key={`${group.type}-${result.id}`}
                                        type="button"
                                        className={`flex w-full items-center gap-3 px-4 py-2 text-start text-sm ${
                                            isActive ? 'bg-[var(--color-primary)] text-white' : 'text-[var(--color-text-primary)] hover:bg-gray-50'
                                        }`}
                                        onMouseEnter={() => setActive(currentIndex)}
                                        onClick={() => go(result)}
                                    >
                                        <span className={`font-mono text-xs ${isActive ? 'text-white/70' : 'text-gray-400'}`}>
                                            {result.reference}
                                        </span>
                                        <span className="flex-1 truncate">{result.title}</span>
                                        <span className={`text-xs ${isActive ? 'text-white/70' : 'text-gray-400'}`}>{result.meta}</span>
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
