export default function InputLabel({ value, required = false, className = '', children, ...props }) {
    return (
        <label {...props} className={`form-label ${className}`}>
            {value || children}
            {required && <span className="ml-0.5 text-red-500">*</span>}
        </label>
    );
}
