export default function InputError({ message, className = '', ...props }) {
    if (!message) return null;

    return (
        <p {...props} className={`text-sm text-[var(--color-error)] ${className}`}>
            {message}
        </p>
    );
}
