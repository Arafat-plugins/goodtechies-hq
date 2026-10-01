// Brief 025: the task description's links, run by Node's own runner (`npm run test:js`).
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { linkify } from '../../resources/js/lib/linkify.ts';

const links = (text: string) =>
    linkify(text)
        .filter((segment) => segment.kind === 'link')
        .map((segment) => (segment.kind === 'link' ? [segment.text, segment.href] : []));

test('plain text is one text segment', () => {
    assert.deepEqual(linkify('No links here.'), [{ kind: 'text', text: 'No links here.' }]);
    assert.deepEqual(linkify(''), []);
});

test('http, https and www are recognised; www gets https', () => {
    assert.deepEqual(links('see http://a.test and https://b.test/x?y=1#z or www.c.test'), [
        ['http://a.test', 'http://a.test'],
        ['https://b.test/x?y=1#z', 'https://b.test/x?y=1#z'],
        ['www.c.test', 'https://www.c.test'],
    ]);
});

test('the text around a link survives unchanged, in order', () => {
    const text = 'Before https://a.test/p, after.';

    assert.equal(
        linkify(text)
            .map((segment) => segment.text)
            .join(''),
        text,
    );
    assert.deepEqual(links(text), [['https://a.test/p', 'https://a.test/p']]);
});

test('trailing punctuation and an unmatched closing bracket are left out', () => {
    assert.deepEqual(links('(see https://a.test/b).'), [['https://a.test/b', 'https://a.test/b']]);
    assert.deepEqual(links('https://en.test/Foo_(bar)'), [['https://en.test/Foo_(bar)', 'https://en.test/Foo_(bar)']]);
    assert.deepEqual(links('Done: https://a.test!'), [['https://a.test', 'https://a.test']]);
});

test('no other scheme is ever linked, and markup stays text', () => {
    assert.deepEqual(links('javascript:alert(1) data:text/html,x ftp://a.test'), []);
    assert.deepEqual(linkify('<script>alert(1)</script>'), [{ kind: 'text', text: '<script>alert(1)</script>' }]);
    assert.deepEqual(links('<a href="https://a.test">x</a>'), [['https://a.test', 'https://a.test']]);
});

test('a URL inside a word or an email is not picked out', () => {
    assert.deepEqual(links('user@www.a.test notwww.a.test'), []);
    assert.deepEqual(links('https:// www.'), []);
});
