// Polish 047: Discord-style formatting in messages. Run by Node's own runner (`npm run test:js`).
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    hasFormatting,
    parseInline,
    parseMessage,
    plainText,
    toggleLinePrefix,
    toggleWrap,
    wrapCodeBlock,
} from '../../resources/js/Components/Messages/markdown.ts';

test('inline styles', () => {
    assert.deepEqual(parseInline('a **b** c'), [
        { kind: 'text', value: 'a ' },
        { kind: 'bold', children: [{ kind: 'text', value: 'b' }] },
        { kind: 'text', value: ' c' },
    ]);
    assert.equal(parseInline('*i*')[0].kind, 'italic');
    assert.equal(parseInline('_i_')[0].kind, 'italic');
    assert.equal(parseInline('__u__')[0].kind, 'underline');
    assert.equal(parseInline('~~s~~')[0].kind, 'strike');
    assert.equal(parseInline('||secret||')[0].kind, 'spoiler');
    assert.deepEqual(parseInline('`**not bold**`'), [{ kind: 'code', value: '**not bold**' }]);
});

test('nesting', () => {
    const [bold] = parseInline('**bold _and italic_**');
    assert.equal(bold.kind, 'bold');
    assert.ok(bold.kind === 'bold' && bold.children.some((n) => n.kind === 'italic'));
});

test('things that are not formatting stay text', () => {
    for (const text of ['2 * 3 * 4', 'snake_case_name', 'a ** b', 'lonely *star', '**', '``', 'my_file_v2.txt']) {
        assert.deepEqual(parseInline(text), [{ kind: 'text', value: text }], text);
    }
    assert.deepEqual(parseInline('\\*not italic\\*'), [{ kind: 'text', value: '*not italic*' }]);
});

test('blocks: headings, quotes, code', () => {
    const blocks = parseMessage('# Big\n## Medium\n### Small\n#### not a heading\n> quoted\n> more\nafter');
    assert.deepEqual(
        blocks.map((b) => b.kind),
        ['heading', 'heading', 'heading', 'paragraph', 'quote', 'paragraph'],
    );
    assert.equal(blocks[0].kind === 'heading' && blocks[0].level, 1);
    assert.equal(blocks[4].kind === 'quote' && blocks[4].blocks.length, 1);

    const code = parseMessage('look:\n```js\nconst a = **1**;\n```\ndone');
    assert.deepEqual(code[1], { kind: 'code', value: 'const a = **1**;' });
    assert.deepEqual(parseMessage('```one line```'), [{ kind: 'code', value: 'one line' }]);
    // An unclosed fence is just text.
    assert.equal(parseMessage('```\nnever closed')[0].kind, 'paragraph');
    assert.equal(parseMessage('#hashtag')[0].kind, 'paragraph');
});

test('a plain message has no formatting and keeps its lines', () => {
    assert.equal(hasFormatting('hello\nthere 2*3'), false);
    assert.equal(hasFormatting('**hi**'), true);
    assert.equal(hasFormatting('> hi'), true);
    assert.deepEqual(parseMessage('a\n\nb'), [{ kind: 'paragraph', inline: [{ kind: 'text', value: 'a\n\nb' }] }]);
});

test('plainText drops the marks and hides spoilers', () => {
    assert.equal(plainText('**Office** closed _Thursday_ ||surprise||'), 'Office closed Thursday ▒▒▒');
    assert.equal(plainText('# Title\n> quote'), 'Title\nquote');
});

test('toggleWrap wraps, unwraps and keeps spaces outside', () => {
    assert.deepEqual(toggleWrap('say hi now', 4, 6, '**'), { value: 'say **hi** now', from: 6, to: 8 });
    assert.deepEqual(toggleWrap('say **hi** now', 6, 8, '**'), { value: 'say hi now', from: 4, to: 6 });
    assert.deepEqual(toggleWrap('say **hi** now', 4, 10, '**'), { value: 'say hi now', from: 4, to: 6 });
    assert.deepEqual(toggleWrap('say hi now', 3, 7, '*'), { value: 'say *hi* now', from: 5, to: 7 });
    assert.deepEqual(toggleWrap('ab', 1, 1, '~~'), { value: 'a~~~~b', from: 3, to: 3 });
});

test('line prefixes and code blocks', () => {
    assert.deepEqual(toggleLinePrefix('one\ntwo', 0, 7, '> '), { value: '> one\n> two', from: 0, to: 11 });
    assert.deepEqual(toggleLinePrefix('> one\n> two', 2, 9, '> '), { value: 'one\ntwo', from: 0, to: 7 });
    assert.equal(wrapCodeBlock('x', 0, 1).value, '```\nx\n```');
});
