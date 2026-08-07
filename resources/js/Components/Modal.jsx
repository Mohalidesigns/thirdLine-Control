import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { Fragment } from 'react';

const MAX_WIDTHS = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
    '4xl': 'sm:max-w-4xl',
};

export default function Modal({ show = false, maxWidth = '2xl', closeable = true, onClose = () => {}, title, children }) {
    const close = () => closeable && onClose();

    return (
        <Transition show={show} as={Fragment} leave="duration-200">
            <Dialog as="div" className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto px-4 py-6" onClose={close}>
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-[var(--color-primary-dark)]/60" />
                </TransitionChild>

                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enterTo="opacity-100 translate-y-0 sm:scale-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                    leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <DialogPanel className={`relative w-full transform overflow-hidden rounded-xl bg-white shadow-xl transition-all ${MAX_WIDTHS[maxWidth] ?? MAX_WIDTHS['2xl']}`}>
                        {title && (
                            <div className="border-b border-gray-100 px-6 py-4">
                                <h3 className="text-lg font-semibold text-[var(--color-text-primary)]">{title}</h3>
                            </div>
                        )}
                        <div className="p-6">{children}</div>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}
