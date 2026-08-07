export default function SelectInput({ className = '', children, ...props }) {
    return (
        <select {...props} className={`form-select ${className}`}>
            {children}
        </select>
    );
}
