export default function SecondaryButton({ className = '', disabled, children, ...props }) {
    return (
        <button type="button" {...props} disabled={disabled} className={`btn-secondary ${className}`}>
            {children}
        </button>
    );
}
