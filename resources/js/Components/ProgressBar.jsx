export default function ProgressBar({ value = 0, color = 'var(--color-success)' }) {
    const clamped = Math.min(100, Math.max(0, value));

    return (
        <div className="progress-bar">
            <div className="progress-bar-fill" style={{ width: `${clamped}%`, backgroundColor: color }} />
        </div>
    );
}
