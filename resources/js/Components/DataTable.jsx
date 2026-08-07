import EmptyState from '@/Components/EmptyState';

export default function DataTable({ columns, data, emptyMessage = 'No records found.', emptyAction = null, onRowClick }) {
    if (!data || data.length === 0) {
        return <EmptyState title={emptyMessage} action={emptyAction} />;
    }

    return (
        <div className="overflow-x-auto">
            <table className="data-table">
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column.field} style={column.width ? { width: column.width } : undefined}>
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {data.map((row, index) => (
                        <tr
                            key={row.id ?? index}
                            onClick={onRowClick ? () => onRowClick(row) : undefined}
                            className={onRowClick ? 'cursor-pointer' : undefined}
                        >
                            {columns.map((column) => (
                                <td key={column.field}>
                                    {column.render ? column.render(row) : (row[column.field] ?? '—')}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
