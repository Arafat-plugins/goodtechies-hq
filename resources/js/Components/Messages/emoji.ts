/**
 * The emoji the composer's picker offers, and the reader's recently used ones.
 *
 * A short curated list rather than the whole Unicode table: the picker is for the dozen faces
 * and gestures a team actually sends, and a 3 000-glyph grid would be a search problem this
 * screen does not need. Everything is a plain string, so it reaches the server as ordinary text.
 */

export interface EmojiGroup {
    label: string;
    emoji: string[];
}

export const EMOJI_GROUPS: EmojiGroup[] = [
    {
        label: 'Smileys',
        emoji: [
            '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '🙂', '🙃',
            '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😋', '😜', '🤔',
            '🤨', '😐', '😑', '😶', '🙄', '😏', '😴', '😮', '😢', '😭',
            '😡', '🥳',
        ],
    },
    {
        label: 'Gestures',
        emoji: [
            '👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉',
            '👆', '👇', '☝️', '✋', '🤚', '🖐️', '🖖', '👋', '🤝', '🙏',
            '👏', '🙌', '👐', '🤲', '💪', '✍️', '🤌', '🫡', '🫶', '👊',
        ],
    },
    {
        label: 'People',
        emoji: [
            '👶', '🧒', '👦', '👧', '🧑', '👨', '👩', '🧓', '👴', '👵',
            '🙋', '🙆', '🙅', '🤷', '🤦', '💁', '🙇', '🧑‍💻', '👨‍💻', '👩‍💻',
            '🧑‍💼', '👨‍💼', '👩‍💼', '🧑‍🎨', '🧑‍🔧', '🧑‍🏫', '🕵️', '👷', '🧑‍🤝‍🧑', '👪',
        ],
    },
    {
        label: 'Hearts',
        emoji: [
            '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔',
            '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '❤️‍🔥',
            '❤️‍🩹', '💌', '😻', '💋', '🫀', '♥️', '💑', '💏', '🌹', '💐',
        ],
    },
    {
        label: 'Animals & nature',
        emoji: [
            '🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯',
            '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦', '🦋', '🐝',
            '🌸', '🌻', '🌳', '🌴', '🍀', '🌈', '☀️', '🌙', '⭐', '🔥',
        ],
    },
    {
        label: 'Food',
        emoji: [
            '🍎', '🍌', '🍉', '🍇', '🍓', '🍒', '🥭', '🍍', '🥥', '🥑',
            '🍅', '🌽', '🥕', '🍞', '🧀', '🍳', '🍔', '🍟', '🍕', '🌭',
            '🍜', '🍛', '🍚', '🍣', '🍰', '🎂', '🍩', '🍪', '☕', '🍵',
        ],
    },
    {
        label: 'Objects',
        emoji: [
            '💻', '🖥️', '⌨️', '🖱️', '📱', '☎️', '📷', '🎧', '🎤', '📺',
            '💡', '🔋', '🔌', '📦', '📁', '📄', '📝', '📌', '📎', '✂️',
            '🗓️', '📅', '📊', '📈', '🔒', '🔑', '🔨', '🛠️', '⏰', '🎁',
        ],
    },
    {
        label: 'Symbols',
        emoji: [
            '✅', '☑️', '✔️', '❌', '❎', '⚠️', '🚫', '⛔', '❓', '❗',
            '‼️', '⁉️', '💯', '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⚫',
            '⚪', '➕', '➖', '➡️', '⬅️', '⬆️', '⬇️', '🔁', '🆗', '🆕',
        ],
    },
];

export const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'] as const;

const RECENT_KEY = 'hq.emoji.recent';
const RECENT_MAX = 16;

/** The reader's most recently picked emoji, newest first. Empty when storage is unavailable. */
export function recentEmoji(): string[] {
    try {
        const raw = window.localStorage.getItem(RECENT_KEY);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(parsed)
            ? parsed.filter((item): item is string => typeof item === 'string').slice(0, RECENT_MAX)
            : [];
    } catch {
        return [];
    }
}

/** Put `emoji` at the front of the recent list (deduplicated, at most 16 kept). */
export function rememberEmoji(emoji: string): void {
    try {
        const next = [emoji, ...recentEmoji().filter((item) => item !== emoji)].slice(0, RECENT_MAX);

        window.localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
        // Private window, blocked storage: the picker simply has no "Recent" row.
    }
}
