import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

export default function ConfirmDialog({
    show,
    title = 'Are you sure?',
    message,
    confirmLabel = 'Confirm',
    variant = 'danger',
    processing = false,
    onConfirm,
    onCancel,
}) {
    const ConfirmButton = variant === 'danger' ? DangerButton : PrimaryButton;

    return (
        <Modal show={show} maxWidth="md" onClose={onCancel}>
            <h3 className="text-lg font-semibold text-[var(--color-text-primary)]">{title}</h3>
            {message && <p className="mt-2 text-sm text-[var(--color-text-secondary)]">{message}</p>}
            <div className="mt-6 flex justify-end gap-2">
                <SecondaryButton onClick={onCancel} disabled={processing}>
                    Cancel
                </SecondaryButton>
                <ConfirmButton onClick={onConfirm} disabled={processing}>
                    {confirmLabel}
                </ConfirmButton>
            </div>
        </Modal>
    );
}
