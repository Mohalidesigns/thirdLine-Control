import { toDoc } from '@/Components/RichTextEditor';

const ALLOWED_TAGS = new Set(['B', 'I', 'STRONG', 'EM', 'A', 'MARK', 'CODE', 'U', 'S', 'BR']);

/**
 * Allowlist sanitiser for the inline HTML Editor.js keeps inside text
 * fields. Rebuilds the fragment keeping only known tags, and only a safe
 * href on <a> — everything else (attributes, scripts, unknown elements)
 * is dropped. The server sanitises on write; this guards legacy rows and
 * defence in depth on read.
 */
function sanitizeInline(html) {
    const template = document.createElement('template');
    template.innerHTML = String(html ?? '');

    const rebuild = (node) => {
        let out = '';
        for (const child of node.childNodes) {
            if (child.nodeType === Node.TEXT_NODE) {
                out += child.textContent
                    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
            } else if (child.nodeType === Node.ELEMENT_NODE) {
                const tag = child.tagName;
                if (!ALLOWED_TAGS.has(tag)) {
                    out += rebuild(child); // unwrap unknown elements, keep their text
                } else if (tag === 'BR') {
                    out += '<br>';
                } else if (tag === 'A') {
                    const href = child.getAttribute('href') ?? '';
                    const safe = /^(https?:\/\/|mailto:|\/)/i.test(href.trim()) ? href.trim() : '';
                    out += safe
                        ? `<a href="${safe.replaceAll('"', '&quot;')}" rel="noopener noreferrer" target="_blank">${rebuild(child)}</a>`
                        : rebuild(child);
                } else {
                    const t = tag.toLowerCase();
                    out += `<${t}>${rebuild(child)}</${t}>`;
                }
            }
        }

        return out;
    };

    return rebuild(template.content);
}

function Inline({ html, as: Tag = 'p', className = '' }) {
    // eslint-disable-next-line react/no-danger
    return <Tag className={className} dangerouslySetInnerHTML={{ __html: sanitizeInline(html) }} />;
}

function ListItems({ items, ordered }) {
    const Tag = ordered ? 'ol' : 'ul';

    return (
        <Tag className={`ms-5 space-y-0.5 ${ordered ? 'list-decimal' : 'list-disc'}`}>
            {(items ?? []).map((item, i) => {
                const content = typeof item === 'object' ? (item.content ?? item.text ?? '') : item;
                const children = typeof item === 'object' && item.items?.length > 0 && (
                    <ListItems items={item.items} ordered={ordered} />
                );

                return (
                    <li key={i}>
                        <Inline html={content} as="span" />
                        {children}
                    </li>
                );
            })}
        </Tag>
    );
}

/**
 * Read-only rendering of a rich field. Accepts the Editor.js document
 * (preferred: the `{field}_rich` attribute) and falls back to the plain
 * column for legacy rows.
 */
export default function RichTextViewer({ value, fallback = null, className = '' }) {
    const doc = toDoc(value ?? fallback);

    if (!doc.blocks.length) {
        return <span className="text-gray-400">—</span>;
    }

    return (
        <div className={`rich-text-viewer space-y-2 text-sm text-[var(--color-text-primary)] ${className}`}>
            {doc.blocks.map(({ type, data = {} }, index) => {
                switch (type) {
                    case 'paragraph':
                        return <Inline key={index} html={data.text} />;
                    case 'header': {
                        const level = Math.min(6, Math.max(1, data.level ?? 3));
                        return <Inline key={index} as={`h${level}`} html={data.text} className="font-semibold text-[var(--navy-800)]" />;
                    }
                    case 'quote':
                        return (
                            <blockquote key={index} className="border-l-2 border-[var(--navy-200)] ps-3 italic">
                                <Inline html={data.text} as="span" />
                                {data.caption && <Inline html={data.caption} as="cite" className="block text-xs not-italic text-gray-500" />}
                            </blockquote>
                        );
                    case 'list':
                        return <ListItems key={index} items={data.items} ordered={data.style === 'ordered'} />;
                    case 'checklist':
                        return (
                            <ul key={index} className="space-y-0.5">
                                {(data.items ?? []).map((item, i) => (
                                    <li key={i} className="flex items-start gap-2">
                                        <span aria-hidden="true" className={item.checked ? 'text-emerald-600' : 'text-gray-300'}>
                                            {item.checked ? '☑' : '☐'}
                                        </span>
                                        <Inline html={item.text} as="span" />
                                    </li>
                                ))}
                            </ul>
                        );
                    case 'table':
                        return (
                            <div key={index} className="overflow-x-auto">
                                <table className="min-w-[50%] border border-gray-200 text-sm">
                                    <tbody>
                                        {(data.content ?? []).map((row, r) => (
                                            <tr key={r}>
                                                {row.map((cell, c) =>
                                                    data.withHeadings && r === 0 ? (
                                                        <th key={c} className="border border-gray-200 bg-[var(--navy-50)] px-2 py-1 text-left font-semibold">
                                                            <Inline html={cell} as="span" />
                                                        </th>
                                                    ) : (
                                                        <td key={c} className="border border-gray-200 px-2 py-1">
                                                            <Inline html={cell} as="span" />
                                                        </td>
                                                    ),
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        );
                    case 'code':
                        return (
                            <pre key={index} className="overflow-x-auto rounded-lg bg-[var(--navy-900)] p-3 font-mono text-xs text-white">
                                {String(data.code ?? '')}
                            </pre>
                        );
                    case 'delimiter':
                        return <hr key={index} className="mx-auto my-3 w-1/4 border-gray-200" />;
                    default:
                        return null;
                }
            })}
        </div>
    );
}
