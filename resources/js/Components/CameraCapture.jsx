import { useRef, useState } from 'react';

/**
 * Camera evidence capture (15.2): opens the device camera (or gallery),
 * re-encodes through a canvas — which strips EXIF, including embedded
 * GPS, for privacy — and compresses to the 500KB target. Geotagging is
 * OFF unless the caller passes geotag (tenant-enabled, spot checks only);
 * when on, coordinates are captured explicitly and attached as metadata,
 * never smuggled inside the image file.
 */
const TARGET_BYTES = 500 * 1024;
const MAX_DIMENSION = 1920;

async function compress(file) {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, MAX_DIMENSION / Math.max(bitmap.width, bitmap.height));

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    // Walk quality down until under target — canvas output has no EXIF.
    for (const quality of [0.8, 0.65, 0.5, 0.35, 0.25]) {
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (blob && blob.size <= TARGET_BYTES) return blob;
    }

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.2));
}

export default function CameraCapture({ onCapture, geotag = false, label = 'Capture photo evidence' }) {
    const input = useRef(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    async function handleFile(event) {
        const file = event.target.files?.[0];
        if (!file) return;

        setBusy(true);
        setError(null);

        try {
            const blob = await compress(file);
            const compressed = new File([blob], file.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg' });

            let location = null;

            if (geotag && navigator.geolocation) {
                location = await new Promise((resolve) => {
                    navigator.geolocation.getCurrentPosition(
                        (position) => resolve({
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude,
                            accuracy: position.coords.accuracy,
                        }),
                        () => resolve(null),
                        { timeout: 5000 },
                    );
                });
            }

            onCapture(compressed, { location, original_size: file.size, compressed_size: compressed.size });
        } catch {
            setError('Could not process the image — try again or pick a smaller photo.');
        } finally {
            setBusy(false);
            event.target.value = '';
        }
    }

    return (
        <div>
            <input
                ref={input}
                type="file"
                accept="image/*"
                capture="environment"
                className="hidden"
                onChange={handleFile}
            />
            <button
                type="button"
                className="btn-secondary min-h-[44px] w-full"
                disabled={busy}
                onClick={() => input.current?.click()}
            >
                {busy ? 'Compressing…' : label}
            </button>
            {error && <p className="mt-1 text-sm text-[var(--color-error)]">{error}</p>}
        </div>
    );
}
