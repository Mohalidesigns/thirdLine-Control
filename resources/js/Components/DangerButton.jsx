export default function DangerButton({ className = '', disabled, children, ...props }) {
    return (
        <button type="button" {...props} disabled={disabled} className={`btn-danger ${className}`}>
            {children}
        </button>
    );
}
