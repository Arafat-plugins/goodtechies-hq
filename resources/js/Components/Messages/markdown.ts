/**
 * Polish 047: Discord-style formatting in messages.
 *
 * What somebody types stays plain text in the database. This turns it into a small tree that
 * MessageBody draws with Vue elements — never HTML, so a message still cannot inject markup.
 *
 * Blocks (at the start of a line):
 *   # Heading   ## Smaller   ### Smallest
 *   > quoted line            (consecutive quoted lines are one quote)
 *   ```                      (a code block; an optional language word after the fence is dropped)
 *   code
 *   ```
 *
 * Inline:
 *   **bold**  *italic* or _italic_  __underline__  ~~strike~~  ||spoiler||  `code`
 *   \* — a backslash before a marker keeps the marker as plain text.
 *
 * Inside code nothing is parsed: not formatting, not links, not mentions.
 *
 * Self-contained (no `@/` imports) so Node's own test runner can load it.
 */

export type InlineStyle = 'bold' | 'italic' | 'underline' | 'strike' | 'spoiler';

export type InlineNode =
    | { kind: 'text'; value: string }
    | { kind: 'code'; value: string }
    | { kind: InlineStyle; children: InlineNode[] };

export type Block =
    | { kind: 'paragraph'; inline: InlineNode[] }
    | { kind: 'heading'; level: 1 | 2 | 3; inline: InlineNode[] }
    | { kind: 'quote'; blocks: Block[] }
    | { kind: 'code'; value: string };

/** Longest first, so `**` is tried before `*` and `__` before `_`. */
const DELIMITERS: { mark: string; style: InlineStyle }[] = [
    { mark: '||', style: 'spoiler' },
    { mark: '**', style: 'bold' },
    { mark: '__', style: 'underline' },
    { mark: '~~', style: 'strike' },
    { mark: '*', style: 'italic' },
    { mark: '_', style: 'italic' },
];

const ESCAPABLE = new Set(['\\', '*', '_', '~', '|', '`', '#', '>']);

const WORD = /[\p{L}\p{N}]/u;

function isWord(char: string | undefined): boolean {
    return char !== undefined && WORD.test(char);
}

function pushText(out: InlineNode[], value: string): void {
    if (value === '') {
        return;
    }

    const last = out[out.length - 1];

    if (last && last.kind === 'text') {
        last.value += value;
    } else {
        out.push({ kind: 'text', value });
    }
}

/**
 * Where the closing `mark` for an opener at `open` is, or -1. The content may not be empty or
 * begin/end with a space (so "2 * 3 * 4" stays arithmetic), a single `*` never closes on half
 * of a `**`, and `_` only works at word edges (so snake_case_names stay names).
 */
function findClose(text: string, open: number, mark: string): number {
    const start = open + mark.length;

    for (let at = text.indexOf(mark, start); at !== -1; at = text.indexOf(mark, at + 1)) {
        if (text[at - 1] === '\\') {
            continue;
        }

        const content = text.slice(start, at);

        if (content === '' || /^\s|\s$/u.test(content)) {
            continue;
        }

        if (mark === '*' && (text[at + 1] === '*' || text[at - 1] === '*')) {
            continue;
        }

        if (mark === '_' && isWord(text[at + 1])) {
            continue;
        }

        return at;
    }

    return -1;
}

export function parseInline(text: string): InlineNode[] {
    const out: InlineNode[] = [];
    let plainFrom = 0;
    let i = 0;

    const flush = (to: number) => pushText(out, text.slice(plainFrom, to));

    while (i < text.length) {
        const char = text[i];

        if (char === '\\' && ESCAPABLE.has(text[i + 1] ?? '')) {
            flush(i);
            pushText(out, text[i + 1]);
            i += 2;
            plainFrom = i;

            continue;
        }

        if (char === '`') {
            const close = text.indexOf('`', i + 1);

            if (close > i + 1) {
                flush(i);
                out.push({ kind: 'code', value: text.slice(i + 1, close) });
                i = close + 1;
                plainFrom = i;

                continue;
            }
        }

        let matched = false;

        for (const { mark, style } of DELIMITERS) {
            if (!text.startsWith(mark, i)) {
                continue;
            }

            if (mark === '_' && isWord(text[i - 1])) {
                continue;
            }

            const close = findClose(text, i, mark);

            if (close === -1) {
                continue;
            }

            flush(i);
            out.push({ kind: style, children: parseInline(text.slice(i + mark.length, close)) });
            i = close + mark.length;
            plainFrom = i;
            matched = true;

            break;
        }

        if (!matched) {
            i += 1;
        }
    }

    flush(text.length);

    return out;
}

const HEADING = /^(#{1,3}) (.*\S.*)$/u;
const QUOTE = /^> ?(.*)$/u;
const FENCE = /^```/u;

export function parseMessage(body: string): Block[] {
    return parseLines(body.replace(/\r\n?/gu, '\n').split('\n'), true);
}

function parseLines(lines: string[], allowQuotes: boolean): Block[] {
    const blocks: Block[] = [];
    let paragraph: string[] = [];

    const endParagraph = () => {
        // Blank lines next to a heading, quote or code block are the gap between blocks, which
        // the screen draws itself.
        while (paragraph.length > 0 && paragraph[0].trim() === '') {
            paragraph.shift();
        }

        while (paragraph.length > 0 && paragraph[paragraph.length - 1].trim() === '') {
            paragraph.pop();
        }

        if (paragraph.length > 0) {
            blocks.push({ kind: 'paragraph', inline: parseInline(paragraph.join('\n')) });
        }

        paragraph = [];
    };

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        if (FENCE.test(line)) {
            const close = lines.findIndex((candidate, index) => index > i && /^```\s*$/u.test(candidate));
            const rest = line.slice(3);

            // ```code``` on one line is a block too.
            if (rest.length > 3 && rest.endsWith('```')) {
                endParagraph();
                blocks.push({ kind: 'code', value: rest.slice(0, -3) });

                continue;
            }

            if (close !== -1) {
                endParagraph();
                const first = /^[\w+#.-]*\s*$/u.test(rest) ? [] : [rest];
                blocks.push({ kind: 'code', value: [...first, ...lines.slice(i + 1, close)].join('\n') });
                i = close;

                continue;
            }
        }

        const heading = HEADING.exec(line);

        if (heading) {
            endParagraph();
            blocks.push({
                kind: 'heading',
                level: heading[1].length as 1 | 2 | 3,
                inline: parseInline(heading[2]),
            });

            continue;
        }

        if (allowQuotes && QUOTE.test(line)) {
            endParagraph();
            const quoted: string[] = [];

            while (i < lines.length && QUOTE.test(lines[i])) {
                quoted.push(QUOTE.exec(lines[i])?.[1] ?? '');
                i++;
            }

            i--;
            blocks.push({ kind: 'quote', blocks: parseLines(quoted, false) });

            continue;
        }

        paragraph.push(line);
    }

    endParagraph();

    return blocks;
}

/** True when the body uses any formatting at all — a plain message keeps its plain rendering. */
export function hasFormatting(body: string): boolean {
    const blocks = parseMessage(body);

    return !(blocks.length <= 1 && blocks.every((b) => b.kind === 'paragraph' && b.inline.every((n) => n.kind === 'text')));
}

/** True when the body has a heading, a quote or a code block — it cannot sit on one line with the time. */
export function hasBlocks(body: string): boolean {
    return parseMessage(body).some((b) => b.kind !== 'paragraph');
}

/**
 * The words without the marks — for previews (chat list, reply quote, banner, popups). A spoiler
 * stays hidden in a preview.
 */
export function plainText(body: string): string {
    const inline = (nodes: InlineNode[]): string =>
        nodes
            .map((node) =>
                node.kind === 'text' || node.kind === 'code'
                    ? node.value
                    : node.kind === 'spoiler'
                      ? '▒▒▒'
                      : inline(node.children),
            )
            .join('');

    const block = (b: Block): string =>
        b.kind === 'code' ? b.value : b.kind === 'quote' ? b.blocks.map(block).join('\n') : inline(b.inline);

    return parseMessage(body).map(block).join('\n');
}

/* ------------------------------------------------------------ the composer's helpers */

export interface Edit {
    value: string;
    from: number;
    to: number;
}

/**
 * Wrap the selection in `mark` (Ctrl+B → `**`), or take the marks off when it is already
 * wrapped. With nothing selected, the marks are inserted and the caret lands between them.
 */
export function toggleWrap(value: string, from: number, to: number, mark: string): Edit {
    const before = value.slice(0, from);
    const selected = value.slice(from, to);
    const after = value.slice(to);

    // The selection itself carries the marks: "**word**" selected.
    if (selected.length >= mark.length * 2 && selected.startsWith(mark) && selected.endsWith(mark)) {
        const inner = selected.slice(mark.length, -mark.length);

        return { value: before + inner + after, from, to: from + inner.length };
    }

    // The marks sit just outside the selection: "**[word]**".
    if (before.endsWith(mark) && after.startsWith(mark) && !(mark === '*' && before.endsWith('**') && after.startsWith('**'))) {
        return {
            value: before.slice(0, -mark.length) + selected + after.slice(mark.length),
            from: from - mark.length,
            to: to - mark.length,
        };
    }

    // Keep spaces the selection picked up outside the marks, so "**word **" never happens.
    const lead = /^\s*/u.exec(selected)?.[0] ?? '';
    const trail = /\s*$/u.exec(selected.slice(lead.length))?.[0] ?? '';
    const core = selected.slice(lead.length, selected.length - trail.length);
    const start = before.length + lead.length + mark.length;

    return {
        value: before + lead + mark + core + mark + trail + after,
        from: start,
        to: start + core.length,
    };
}

/** Prefix every selected line with `prefix` ("> " for a quote, "# " for a heading), or take it off. */
export function toggleLinePrefix(value: string, from: number, to: number, prefix: string): Edit {
    const lineStart = value.lastIndexOf('\n', from - 1) + 1;
    const nextBreak = value.indexOf('\n', to);
    const lineEnd = nextBreak === -1 ? value.length : nextBreak;
    const lines = value.slice(lineStart, lineEnd).split('\n');
    const all = lines.every((line) => line.startsWith(prefix));
    const changed = lines.map((line) => (all ? line.slice(prefix.length) : prefix + line)).join('\n');

    return {
        value: value.slice(0, lineStart) + changed + value.slice(lineEnd),
        from: lineStart,
        to: lineStart + changed.length,
    };
}

/** A code block around the selected lines. */
export function wrapCodeBlock(value: string, from: number, to: number): Edit {
    const selected = value.slice(from, to);
    const lead = from > 0 && value[from - 1] !== '\n' ? '\n' : '';
    const trail = to < value.length && value[to] !== '\n' ? '\n' : '';
    const block = `${lead}\`\`\`\n${selected}\n\`\`\`${trail}`;
    const start = from + lead.length + 4;

    return { value: value.slice(0, from) + block + value.slice(to), from: start, to: start + selected.length };
}
