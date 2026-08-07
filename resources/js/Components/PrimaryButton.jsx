export default function PrimaryButton({ className = '', disabled, children, ...props }) {
    return (
        <button {...props} disabled={disabled} className={`btn-primary ${className}`}>
            {children}
        </button>
    );
}
