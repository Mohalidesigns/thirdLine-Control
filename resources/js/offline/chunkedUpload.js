import axios from 'axios';

/**
 * Resumable chunked upload (15.2) for files over the threshold: initiate,
 * send 2MB chunks (each retried independently), then complete — the
 * server assembles and files the evidence through the normal pipeline.
 * onProgress receives 0..1.
 */
export const CHUNK_THRESHOLD = 2 * 1024 * 1024;

const CHUNK_SIZE = 2 * 1024 * 1024;
const RETRIES_PER_CHUNK = 3;

export async function uploadEvidence(file, meta, onProgress = () => {}) {
    if (file.size <= CHUNK_THRESHOLD) {
        const form = new FormData();
        form.append('file', file);
        Object.entries(meta).forEach(([key, value]) => form.append(key, value ?? ''));

        const { data } = await axios.post(route('evidence.store'), form, {
            onUploadProgress: (e) => onProgress(e.total ? e.loaded / e.total : 0),
        });

        return data;
    }

    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);

    const { data: init } = await axios.post(route('uploads.chunked.store'), {
        file_name: file.name,
        mime_type: file.type || null,
        total_size: file.size,
        total_chunks: totalChunks,
        meta,
    });

    for (let index = 0; index < totalChunks; index++) {
        const chunk = file.slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE);
        const form = new FormData();
        form.append('index', index);
        form.append('chunk', chunk, `chunk-${index}`);

        let attempt = 0;

        // eslint-disable-next-line no-constant-condition
        while (true) {
            try {
                await axios.post(route('uploads.chunked.append', init.uuid), form);
                break;
            } catch (error) {
                if (++attempt >= RETRIES_PER_CHUNK) throw error;
                await new Promise((resolve) => setTimeout(resolve, 1000 * attempt));
            }
        }

        onProgress((index + 1) / (totalChunks + 1));
    }

    const { data } = await axios.post(route('uploads.chunked.complete', init.uuid));
    onProgress(1);

    return data;
}
