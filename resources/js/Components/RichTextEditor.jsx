import EditorJS from '@editorjs/editorjs';
import Checklist from '@editorjs/checklist';
import Code from '@editorjs/code';
import Delimiter from '@editorjs/delimiter';
import Header from '@editorjs/header';
import InlineCode from '@editorjs/inline-code';
import List from '@editorjs/list';
import Marker from '@editorjs/marker';
import Quote from '@editorjs/quote';
import Table from '@editorjs/table';
import Underline from '@editorjs/underline';
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

/**
 * Convert whatever the database holds into an Editor.js document:
 * a document object passes through, a JSON string is parsed, and legacy
 * plain text (or anything unparseable) becomes one paragraph per line —
 * no existing record ever renders blank or crashes.
 */
export function toDoc(value) {
    if (value && typeof value === 'object' && Array.isArray(value.blocks)) {
        return value;
    }
    if (typeof value === 'string' && value.trim() !== '') {
        try {
            const parsed = JSON.parse(value);
            if (parsed && Array.isArray(parsed.blocks)) {
                return parsed;
            }
        } catch {
            // fall through to the plain-text migration
        }
        const escape = (line) =>
            line.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');

        return {
            time: 0,
            version: 'plain-migrated',
            blocks: value
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter(Boolean)
                .map((line) => ({ type: 'paragraph', data: { text: escape(line) } })),
        };
    }

    return { time: 0, blocks: [] };
}

/** Plain-text mirror of a document — same shape the server derives. */
export function docToPlain(doc) {
    if (!doc || !Array.isArray(doc.blocks)) return '';
    const strip = (html) => {
        const el = document.createElement('div');
        el.innerHTML = String(html ?? '');
        return el.textContent.trim();
    };
    const listToPlain = (items, depth = 0) =>
        (items ?? [])
            .map((item) => {
                const prefix = `${'  '.repeat(depth)}- `;
                if (item && typeof item === 'object') {
                    const line = prefix + strip(item.content ?? item.text ?? '');
                    const children = listToPlain(item.items, depth + 1);
                    return children ? `${line}\n${children}` : line;
                }
                return prefix + strip(item);
            })
            .join('\n');

    return doc.blocks
        .map(({ type, data = {} }) => {
            switch (type) {
                case 'paragraph':
                case 'header':
                    return strip(data.text);
                case 'quote':
                    return [strip(data.text), data.caption ? `— ${strip(data.caption)}` : ''].filter(Boolean).join(' ');
                case 'list':
                    return listToPlain(data.items);
                case 'checklist':
                    return (data.items ?? []).map((item) => `${item.checked ? '[x]' : '[ ]'} ${strip(item.text)}`).join('\n');
                case 'table':
                    return (data.content ?? []).map((row) => row.map(strip).join(' | ')).join('\n');
                case 'code':
                    return data.code ?? '';
                case 'delimiter':
                    return '⁂';
                default:
                    return '';
            }
        })
        .filter(Boolean)
        .join('\n')
        .trim();
}

// Images and file attachments are deliberately absent from every preset:
// files enter this product through the evidence pipeline (classification,
// personal-data flags, retention) — never pasted into a narrative.
function toolsFor(preset) {
    const minimal = {
        list: { class: List, inlineToolbar: true },
        marker: Marker,
    };
    if (preset === 'minimal') return minimal;

    const base = {
        ...minimal,
        header: { class: Header, inlineToolbar: true, config: { levels: [2, 3, 4], defaultLevel: 3 } },
        checklist: { class: Checklist, inlineToolbar: true },
        quote: { class: Quote, inlineToolbar: true },
        table: { class: Table, inlineToolbar: true },
        underline: Underline,
        inlineCode: InlineCode,
    };
    if (preset === 'full') {
        return { ...base, code: Code, delimiter: Delimiter };
    }

    return base;
}

/**
 * The one rich-text input for every long-form field.
 *
 *   <RichTextEditor value={doc | json-string | plain-string}
 *                   onChange={(doc, plainText) => …}   // debounced ~400ms
 *                   tools="default | minimal | full" />
 *
 * Exposes an imperative save() via ref for forms that submit on click and
 * cannot wait for the debounce.
 */
const RichTextEditor = forwardRef(function RichTextEditor(
    { value, onChange, placeholder = 'Start writing…', minHeight = 160, readOnly = false, tools = 'default', id },
    ref,
) {
    const holderRef = useRef(null);
    const editorRef = useRef(null);
    const debounceRef = useRef(null);
    // Keep the latest callback without re-initialising the editor.
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    const emit = async () => {
        const editor = editorRef.current;
        if (!editor) return null;
        const output = await editor.save();
        onChangeRef.current?.(output, docToPlain(output));

        return output;
    };

    useImperativeHandle(ref, () => ({
        // Flush pending edits immediately (for submit buttons).
        save: async () => {
            clearTimeout(debounceRef.current);
            return emit();
        },
    }));

    useEffect(() => {
        // Initialise exactly once per mount. The cleanup guards React 18
        // StrictMode's mount → unmount → mount cycle: each instance is
        // destroyed through its own isReady promise.
        const editor = new EditorJS({
            holder: holderRef.current,
            data: toDoc(value),
            placeholder,
            readOnly,
            minHeight: 0,
            tools: toolsFor(tools),
            onChange: () => {
                clearTimeout(debounceRef.current);
                debounceRef.current = setTimeout(() => emit().catch(() => {}), 400);
            },
        });
        editorRef.current = editor;

        return () => {
            clearTimeout(debounceRef.current);
            editor.isReady
                .then(() => editor.destroy())
                .catch(() => {});
            if (editorRef.current === editor) {
                editorRef.current = null;
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [readOnly, tools]);

    return (
        <div
            id={id}
            ref={holderRef}
            style={{ minHeight }}
            className={`rich-text-editor form-input ${readOnly ? 'pointer-events-auto bg-gray-50' : ''}`}
        />
    );
});

export default RichTextEditor;
