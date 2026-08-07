import { Transition } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { createContext, useContext, useState } from 'react';

const DropdownContext = createContext();

function Dropdown({ children }) {
    const [open, setOpen] = useState(false);

    return (
        <DropdownContext.Provider value={{ open, setOpen, toggleOpen: () => setOpen((o) => !o) }}>
            <div className="relative">{children}</div>
        </DropdownContext.Provider>
    );
}

function Trigger({ children }) {
    const { open, setOpen, toggleOpen } = useContext(DropdownContext);

    return (
        <>
            <div onClick={toggleOpen}>{children}</div>
            {open && <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />}
        </>
    );
}

function Content({ align = 'right', width = '48', children }) {
    const { open, setOpen } = useContext(DropdownContext);

    const alignment = align === 'left' ? 'origin-top-left start-0' : 'origin-top-right end-0';
    const widthClass = { 48: 'w-48', 60: 'w-60' }[width] ?? 'w-48';

    return (
        <Transition
            show={open}
            enter="transition ease-out duration-200"
            enterFrom="opacity-0 scale-95"
            enterTo="opacity-100 scale-100"
            leave="transition ease-in duration-75"
            leaveFrom="opacity-100 scale-100"
            leaveTo="opacity-0 scale-95"
        >
            <div className={`absolute z-50 mt-2 rounded-lg shadow-lg ${alignment} ${widthClass}`} onClick={() => setOpen(false)}>
                <div className="rounded-lg bg-white py-1 ring-1 ring-black/5">{children}</div>
            </div>
        </Transition>
    );
}

function DropdownLink({ className = '', children, ...props }) {
    return (
        <Link
            {...props}
            className={`block w-full px-4 py-2 text-start text-sm text-gray-700 transition duration-150 ease-in-out hover:bg-gray-50 ${className}`}
        >
            {children}
        </Link>
    );
}

Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;

export default Dropdown;
