// Polish 032: lists in the message composer — pasted lists keep their numbers, Shift+Enter
// continues a list. Run by Node's own runner (`npm run test:js`).
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { continueList, listFromHtml } from '../../resources/js/Components/Messages/composerLists.ts';

test('listFromHtml: no list in the HTML leaves the paste alone', () => {
    assert.equal(listFromHtml(null), null);
    assert.equal(listFromHtml(''), null);
    assert.equal(listFromHtml('<p>Hello <b>there</b></p>'), null);
});

test('listFromHtml: an ordered list keeps its numbers', () => {
    assert.equal(listFromHtml('<ol><li>Alpha</li><li>Beta</li><li>Gamma</li></ol>'), '1. Alpha\n2. Beta\n3. Gamma');
});

test('listFromHtml: an unordered list becomes "- " lines', () => {
    assert.equal(listFromHtml('<ul>\n  <li>one</li>\n  <li>two</li>\n</ul>'), '- one\n- two');
});

test('listFromHtml: start= and value= are honoured', () => {
    assert.equal(listFromHtml('<ol start="4"><li>d</li><li>e</li></ol>'), '4. d\n5. e');
    assert.equal(listFromHtml('<ol><li>a</li><li value="10">j</li><li>k</li></ol>'), '1. a\n10. j\n11. k');
});

test('listFromHtml: nested lists are indented two spaces per level', () => {
    const html = '<ol><li>Top<ul><li>inner a</li><li>inner b<ol><li>deep</li></ol></li></ul></li><li>Next</li></ol>';

    assert.equal(listFromHtml(html), '1. Top\n  - inner a\n  - inner b\n    1. deep\n2. Next');
});

test('listFromHtml: paragraphs, headings and <br> around a list become line breaks', () => {
    const html = '<h2>Plan</h2><p>Steps:</p><ol><li>first</li><li>second<br>more</li></ol><p>Done</p><div>Bye</div>';

    assert.equal(listFromHtml(html), 'Plan\n\nSteps:\n\n1. first\n2. second\nmore\nDone\n\nBye');
});

test('listFromHtml: Google Docs style <p> inside <li> adds no blank lines', () => {
    const html =
        '<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-x">' +
        '<ol style="margin:0"><li dir="ltr" aria-level="1"><p dir="ltr" role="presentation"><span>Buy milk</span></p></li>' +
        '<li dir="ltr" aria-level="1"><p dir="ltr" role="presentation"><span>Call&nbsp;Rahim &amp; co</span></p></li></ol></b>';

    assert.equal(listFromHtml(html), '1. Buy milk\n2. Call Rahim & co');
});

test('listFromHtml: comments, styles and entities are cleaned, 3+ blank lines collapse to 2', () => {
    const html =
        '<html><head><style>li{color:red}</style></head><body><!--StartFragment-->' +
        '<p>A</p><p></p><p></p><p></p><ul><li>x &lt; y</li><li>&#8220;q&#x201D;</li></ul><!--EndFragment--></body></html>';

    assert.equal(listFromHtml(html), 'A\n\n- x < y\n- “q”');
});

test('continueList: a numbered line continues with the next number', () => {
    assert.deepEqual(continueList('1. hello'), { kind: 'continue', insert: '\n2. ' });
    assert.deepEqual(continueList('9. nine'), { kind: 'continue', insert: '\n10. ' });
    assert.deepEqual(continueList('  3) x'), { kind: 'continue', insert: '\n  4) ' });
});

test('continueList: a bullet line continues with the same bullet and indent', () => {
    assert.deepEqual(continueList('- a'), { kind: 'continue', insert: '\n- ' });
    assert.deepEqual(continueList('* b'), { kind: 'continue', insert: '\n* ' });
    assert.deepEqual(continueList('    • c'), { kind: 'continue', insert: '\n    • ' });
});

test('continueList: a line that is only the marker ends the list', () => {
    assert.deepEqual(continueList('2. '), { kind: 'end', remove: 3 });
    assert.deepEqual(continueList('  4)  '), { kind: 'end', remove: 6 });
    assert.deepEqual(continueList('- '), { kind: 'end', remove: 2 });
});

test('continueList: any other line is a plain newline', () => {
    assert.equal(continueList(''), null);
    assert.equal(continueList('hello'), null);
    assert.equal(continueList('2.5 litres'), null);
    assert.equal(continueList('1.'), null);
    assert.equal(continueList('-dash'), null);
    assert.equal(continueList('**bold**'), null);
});
