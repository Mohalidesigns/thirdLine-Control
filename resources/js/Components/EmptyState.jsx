import { Inbox } from 'lucide-react';
import { isValidElement } from 'react';

/**
 * Empty table/list state: centred muted icon, one-line explanation, and
 * the primary action. `icon` accepts a lucide component or an element.
 */
export default function EmptyState({ icon = Inbox, title, description, action = null }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
            {isValidElement(icon)
                ? icon
                : (() => { const Icon = icon; return <Icon className="h-10 w-10 text-gray-300" strokeWidth={1.5} aria-hidden="true" />; })()}
            <p className="text-sm font-semibold text-gray-600">{title}</p>
            {description && <p className="max-w-md text-sm text-gray-400">{description}</p>}
            {action}
        </div>
    );
}
