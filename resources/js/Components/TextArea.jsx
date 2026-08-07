export default function TextArea({ className = '', rows = 3, ...props }) {
    return <textarea {...props} rows={rows} className={`form-input ${className}`} />;
}
