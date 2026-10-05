/**
 * Polish 017: make a big photo or screenshot small before it is uploaded.
 *
 * The server is in Dallas and the team is in Bangladesh, so every byte of an upload crosses the
 * world on a phone or office uplink. A pasted screenshot is a PNG of 2–6 MB; the same picture as
 * a JPEG at 0.85, no wider than 2560 px, is usually a fifth of that and looks the same in a chat.
 *
 * Only PNG/JPEG/WebP over 700 KB are touched, and the smaller copy is used only when it saves at
 * least 30%. Anything that fails (an odd file, no canvas) quietly sends the original.
 */

const SHRINK_FROM_BYTES = 700 * 1024;
const MAX_SIDE = 2560;
const QUALITY = 0.85;
const SHRINKABLE = ['image/png', 'image/jpeg', 'image/webp'];

export async function shrinkImage(file: File): Promise<File> {
    if (!SHRINKABLE.includes(file.type) || file.size < SHRINK_FROM_BYTES || typeof createImageBitmap !== 'function') {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));
        const canvas = document.createElement('canvas');

        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d');

        if (context === null) {
            bitmap.close();

            return file;
        }

        // JPEG has no transparency: a transparent PNG gets a white page behind it, not black.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(bitmap, 0, 0, width, height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));

        if (blob === null || blob.size > file.size * 0.7) {
            return file;
        }

        const name = file.name.replace(/\.[^./\\]+$/, '') || 'image';

        return new File([blob], `${name}.jpg`, { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}

/** "3.7 MB", "640 KB" — for the upload line. */
export function formatBytes(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
