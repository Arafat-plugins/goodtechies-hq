/**
 * Polish 032: lists in the message composer, which stays plain text.
 *
 * - `listFromHtml()` turns a pasted HTML fragment that holds a list (Google Docs, Word on the
 *   web, a web page, Notion…) into plain lines that keep their numbers — "1. ", "2. " — or "- "
 *   for bullets. The browser's own text/plain for such a copy often drops the numbers.
 * - `continueList()` decides what Shift+Enter does on a list line: the next number, the same
 *   bullet, or — on a line that is only the marker — end the list.
 *
 * Both are pure string functions (no DOM), so Node's own test runner checks them.
 */

const ENTITIES: Record<string, string> = {
    amp: '&',
    lt: '<',
    gt: '>',
    quot: '"',
    apos: "'",
    nbsp: ' ',
    ndash: '–',
    mdash: '—',
    hellip: '…',
    lsquo: '‘',
    rsquo: '’',
    ldquo: '“',
    rdquo: '”',
    bull: '•',
};

function decode(text: string): string {
    return text.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (whole, name: string) => {
        if (name[0] === '#') {
            const code = name[1] === 'x' || name[1] === 'X' ? parseInt(name.slice(2), 16) : parseInt(name.slice(1), 10);

            return Number.isFinite(code) && code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : whole;
        }

        return ENTITIES[name.toLowerCase()] ?? whole;
    });
}

function numberAttr(attrs: string, name: string): number | null {
    const match = new RegExp(`\\b${name}\\s*=\\s*["']?(-?\\d+)`, 'i').exec(attrs);

    return match ? Number(match[1]) : null;
}

const BLOCK = new Set(['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'tr', 'table', 'section', 'article', 'header', 'footer']);
const PARAGRAPH = new Set(['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre']);

/**
 * The pasted HTML as plain lines, when it holds a list (`<ol>` or `<ul>`); `null` when it does
 * not, so the paste stays the browser's own.
 *
 * Each `<li>` is its own line: "N. " in an ordered list (counting from `start=`, or an item's
 * own `value=`), "- " in an unordered one, two spaces of indent per level of nesting.
 * `<p>`, `<br>`, `<div>` and headings become line breaks; three or more blank lines become two.
 */
export function listFromHtml(html: string | null | undefined): string | null {
    if (!html || !/<(ol|ul)\b/i.test(html)) {
        return null;
    }

    const source = html
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<(head|style|script|title|template)\b[\s\S]*?<\/\1\s*>/gi, '');

    const lists: { ordered: boolean; next: number }[] = [];
    let out = '';
    let atLineStart = true;

    const ensureBreak = (): void => {
        if (out !== '' && !out.endsWith('\n')) {
            out += '\n';
        }
        atLineStart = true;
    };

    const blankLine = (): void => {
        ensureBreak();
        if (out !== '' && !out.endsWith('\n\n')) {
            out += '\n';
        }
    };

    const token = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>|([^<]+)|</g;
    let match: RegExpExecArray | null;

    while ((match = token.exec(source)) !== null) {
        const [, closing, rawTag, attrs = '', rawText] = match;

        if (rawText !== undefined || rawTag === undefined) {
            let text = decode(rawText ?? '<').replace(/\s+/g, ' ');

            if (atLineStart) {
                text = text.trimStart();
            } else if (out.endsWith(' ')) {
                text = text.replace(/^ /, '');
            }

            if (text !== '') {
                out += text;
                atLineStart = false;
            }

            continue;
        }

        const tag = rawTag.toLowerCase();

        if (tag === 'br') {
            out = out.replace(/ +$/, '') + '\n';
            atLineStart = true;
        } else if (tag === 'ol' || tag === 'ul') {
            ensureBreak();
            if (closing) {
                lists.pop();
            } else {
                lists.push({ ordered: tag === 'ol', next: numberAttr(attrs, 'start') ?? 1 });
            }
        } else if (tag === 'li') {
            ensureBreak();
            const list = lists[lists.length - 1];

            if (!closing) {
                const indent = '  '.repeat(Math.max(0, lists.length - 1));
                let marker = '- ';

                if (list?.ordered) {
                    const value = numberAttr(attrs, 'value');
                    const number = value ?? list.next;
                    list.next = number + 1;
                    marker = `${number}. `;
                }

                out += indent + marker;
                atLineStart = true;
            }
        } else if (PARAGRAPH.has(tag)) {
            if (lists.length > 0) {
                // Google Docs wraps every item's text in a <p>: no blank lines inside a list.
                if (closing) {
                    ensureBreak();
                }
            } else if (closing) {
                blankLine();
            } else {
                ensureBreak();
            }
        } else if (BLOCK.has(tag)) {
            if (lists.length === 0 || closing) {
                ensureBreak();
            }
        } else if (tag === 'td' || tag === 'th') {
            if (closing && !atLineStart) {
                out += ' ';
            }
        }
    }

    const text = out
        .split('\n')
        .map((line) => line.replace(/\s+$/, ''))
        // A marker left with nothing after it (an empty <li>) keeps its trailing space trimmed.
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .replace(/^\n+|\n+$/g, '');

    return text === '' ? null : text;
}

export type ListContinuation =
    /** Insert this text at the caret: a newline, the indent and the next marker. */
    | { kind: 'continue'; insert: string }
    /** The line is only a marker: remove `remove` characters before the caret, then a newline. */
    | { kind: 'end'; remove: number };

/**
 * What Shift+Enter does after `lineBefore` — the caret's line, from its start to the caret.
 *
 * - "N. text" / "N) text" (any indent) → a newline, the same indent and "N+1. " / "N+1) ".
 * - "- text", "* text", "• text" → a newline, the same indent and the same marker.
 * - only the marker ("2. ", "- ") → end the list: the marker goes, a plain newline comes.
 * - anything else → `null`: a plain newline, as always.
 */
export function continueList(lineBefore: string): ListContinuation | null {
    const ordered = /^([ \t]*)(\d{1,9})([.)])([ \t]+)(.*)$/.exec(lineBefore);

    if (ordered) {
        const [, indent, number, separator, , rest] = ordered;

        if (rest.trim() === '') {
            return { kind: 'end', remove: lineBefore.length };
        }

        return { kind: 'continue', insert: `\n${indent}${Number(number) + 1}${separator} ` };
    }

    const bullet = /^([ \t]*)([-*•])([ \t]+)(.*)$/.exec(lineBefore);

    if (bullet) {
        const [, indent, marker, , rest] = bullet;

        if (rest.trim() === '') {
            return { kind: 'end', remove: lineBefore.length };
        }

        return { kind: 'continue', insert: `\n${indent}${marker} ` };
    }

    return null;
}
