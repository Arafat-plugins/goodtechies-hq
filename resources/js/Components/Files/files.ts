import {
    FileArchive,
    File as FileGlyph,
    FileImage,
    FileSpreadsheet,
    FileText,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * The attachment payload, the endpoints that write it, and the limits the panel states before
 * anybody spends four minutes uploading something the server was always going to refuse.
 *
 * `FilePanel.vue` is owner-agnostic on purpose — a task, a project and a client are the same
 * thing to it — so nothing here knows which record the files hang off beyond the URLs it is
 * handed. That is also what lets the employee task mount pass its own routes without the panel
 * gaining a second idea of what surface it is on.
 */

export type FileSurface = 'admin' | 'employee';

/** The URL segment a record's file collection lives under, which is its route prefix. */
export type FileOwnerKind = 'tasks' | 'projects' | 'clients';

export interface FilePerson {
    id: number;
    name: string | null;
}

/**
 * One file as `FileResource` sends it.
 *
 * `url` is a signed, expiring link into this application and **not** a bearer token: the
 * download route runs `FilePolicy::view` on every fetch, so a forwarded link is a 404 for
 * somebody who may not see the owning record. `url_expires_at` rides beside it so a tab that
 * has been open since this morning can tell that its links are dead without discovering it one
 * click at a time.
 *
 * `permissions` is the policy's answer, resolved per requester per file. The panel renders it
 * and never computes it — the endpoint checks the same policy again, and a UI that guessed
 * would only ever be wrong in the direction of offering a button that 403s.
 */
export interface FileSummary {
    id: number;
    name: string;
    extension: string;
    mime_type: string;
    size: number;
    size_label: string;

    is_image: boolean;
    is_pdf: boolean;
    is_previewable: boolean;

    version: number;
    is_current: boolean;
    superseded_at: string | null;

    uploaded_by: FilePerson | null;
    uploaded_at: string | null;

    url: string;
    url_expires_at: string;

    permissions: {
        can_delete: boolean;
        can_replace: boolean;
    };
}

/** The JSON index endpoint's body. */
export interface FileIndexResponse {
    files: FileSummary[];
}

/**
 * The JSON history endpoint's body: one file's whole chain, OLDEST FIRST and including the
 * current version, which is the row whose `is_current` is true.
 *
 * The same `FileSummary` as the index, not a reduced one — each version is a file, with its own
 * signed `url`, its own uploader and its own server-answered `permissions`. A chain is never
 * nested inside one of its own rows: `version_of` points at the ROOT on every row, so there is
 * no row a chain hangs off that a caller would ever hold.
 */
export interface FileHistoryResponse {
    versions: FileSummary[];
}

export interface FileRoutes {
    /** `GET`, answering JSON — the panel fetches this itself, on mount and after every write. */
    index: string;
    /** `POST`, multipart, field `file`. */
    store: string;
    /**
     * `GET`, answering JSON — one file's version chain, fetched when the history disclosure is
     * opened and not before. A file the requester may not see answers 404, its version count
     * included.
     */
    history: (id: number) => string;
    /** `POST` a replacement, multipart, field `file`. The old row is superseded, never lost. */
    version: (id: number) => string;
    /** `DELETE` one row. Deleting is not superseding: the bytes go. */
    destroy: (id: number) => string;
}

/**
 * Every file endpoint for one surface, spelled once.
 *
 * The overloads are the route table, not a preference: the employee surface has the task
 * routes and the two per-file routes and **nothing else**, so asking for an employee project's
 * files is a compile error rather than a 404 somebody finds in staging.
 */
export function fileRoutes(surface: 'admin', owner: FileOwnerKind, ownerId: number): FileRoutes;
export function fileRoutes(surface: 'employee', owner: 'tasks', ownerId: number): FileRoutes;
export function fileRoutes(surface: FileSurface, owner: FileOwnerKind, ownerId: number): FileRoutes {
    const collection = `/${surface}/${owner}/${ownerId}/files`;

    return {
        index: collection,
        store: collection,
        // The same URL as `version`, read instead of written — the chain and the act of
        // extending it are the same resource on both surfaces.
        history: (id: number) => `/${surface}/files/${id}/versions`,
        version: (id: number) => `/${surface}/files/${id}/versions`,
        destroy: (id: number) => `/${surface}/files/${id}`,
    };
}

/* ------------------------------------------------------------------- the limits */

/**
 * `FileService::MAX_BYTES` and the keys of `FileService::TYPES`, restated for the browser.
 *
 * A second copy of a rule is normally how the two drift, and this one is allowed for exactly
 * one reason: the server's refusal arrives after the bytes do. A 40 MB video rejected here
 * costs nothing; rejected there it costs the upload. The server is still the rule — everything
 * below is a courtesy, and the panel renders the server's own sentence whenever it disagrees.
 */
export const FILE_MAX_BYTES = 25 * 1024 * 1024;

export const FILE_EXTENSIONS = [
    'png',
    'jpg',
    'jpeg',
    'gif',
    'webp',
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'ppt',
    'pptx',
    'csv',
    'txt',
    'md',
    'zip',
] as const;

/** The `accept` attribute, so the OS picker greys out what would be refused anyway. */
export const FILE_ACCEPT = FILE_EXTENSIONS.map((extension) => `.${extension}`).join(',');

export const FILE_MAX_LABEL = `${Math.round(FILE_MAX_BYTES / 1048576)} MB`;

function megabytes(bytes: number): string {
    return `${Math.round((bytes / 1048576) * 10) / 10} MB`;
}

/**
 * Why this file would be refused, in the words the server would use — or null to send it.
 *
 * Deliberately not a mime check. The server pairs the extension with the *detected* content
 * type, which a browser cannot do, so a `.png` full of PDF still has to travel to be caught.
 * Guessing here would mean refusing a file the server would have taken.
 */
export function rejectionFor(file: File): string | null {
    if (file.size <= 0) {
        return 'That file is empty.';
    }

    if (file.size > FILE_MAX_BYTES) {
        return `That file is ${megabytes(file.size)} and the limit is ${FILE_MAX_LABEL}.`;
    }

    const extension = file.name.includes('.')
        ? file.name.slice(file.name.lastIndexOf('.') + 1).toLowerCase()
        : '';

    if (!(FILE_EXTENSIONS as readonly string[]).includes(extension)) {
        return `${extension === '' ? 'Extensionless' : extension.toUpperCase()} files are not accepted. `
            + `Allowed: ${FILE_EXTENSIONS.join(', ')}.`;
    }

    return null;
}

/* ------------------------------------------------------------------- presentation */

/**
 * A glyph for the row. `is_image` and `is_pdf` come from the server rather than from the
 * extension, because they are the same two answers the download response sets its disposition
 * from — one decision, read twice.
 */
export function iconFor(file: FileSummary): Component {
    if (file.is_image) {
        return FileImage;
    }

    if (file.is_pdf) {
        return FileText;
    }

    if (file.extension === 'zip') {
        return FileArchive;
    }

    if (file.extension === 'csv' || file.extension === 'xls' || file.extension === 'xlsx') {
        return FileSpreadsheet;
    }

    return FileGlyph;
}

const DATE_TIME = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' });

/**
 * Its own formatter rather than the tasks module's: files are mounted on three kinds of record
 * and a dependency from here into `Components/Tasks/` would point the wrong way.
 */
export function formatUploadedAt(value: string | null | undefined): string {
    if (!value) {
        return 'Unknown date';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE_TIME.format(date);
}
