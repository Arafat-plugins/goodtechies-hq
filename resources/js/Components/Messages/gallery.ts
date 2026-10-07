import type { ComputedRef, InjectionKey } from 'vue';

/**
 * Polish 030: every picture in the open conversation, oldest first, so the lightbox can slide
 * from one to the next (Telegram's ← / →) instead of showing one picture at a time.
 *
 * Provided by MessageThread and read by AttachmentCard; anywhere else an attachment is shown
 * (the context panel's shared files) there is no gallery and the lightbox shows one picture.
 */
export interface GalleryImage {
    id: number;
    src: string;
    name: string;
    /** The signed link the Download control saves from. */
    href: string;
}

export const CHAT_GALLERY: InjectionKey<ComputedRef<GalleryImage[]>> = Symbol('chat-gallery');
