/**
 * Tab bar with leading icons and count chips.
 * tabs: [{ name, label?, icon?, count? }] — `name` is the identity passed
 * to onChange; `label` defaults to name.
 */
export default function TabBar({ tabs, active, onChange }) {
    return (
        <div className="mb-6 border-b border-gray-200">
            <nav className="-mb-px flex gap-6 overflow-x-auto">
                {tabs.map(({ name, label, icon: Icon, count }) => {
                    const isActive = active === name;
                    return (
                        <button
                            key={name}
                            type="button"
                            onClick={() => onChange(name)}
                            aria-current={isActive ? 'page' : undefined}
                            className={`flex items-center gap-1.5 whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition-colors ${
                                isActive
                                    ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                                    : 'border-transparent text-gray-500 hover:text-[var(--color-text-primary)]'
                            }`}
                        >
                            {Icon && <Icon className="h-4 w-4 shrink-0" strokeWidth={1.8} aria-hidden="true" />}
                            {label ?? name}
                            {count != null && (
                                <span
                                    className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none ${
                                        isActive ? 'bg-[var(--color-primary)]/10 text-[var(--color-primary)]' : 'bg-gray-100 text-gray-500'
                                    }`}
                                >
                                    {count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </nav>
        </div>
    );
}
