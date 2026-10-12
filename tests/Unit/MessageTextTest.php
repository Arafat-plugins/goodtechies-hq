<?php

use App\Support\MessageText;

/*
| Polish 047: previews (chat list, reply quote, announcement strip, search, phone popup) show a
| formatted message's words without its marks; a spoiler stays hidden.
*/

it('takes the formatting marks off', function (string $body, string $plain) {
    expect(MessageText::plain($body))->toBe($plain);
})->with([
    'bold' => ['**Office** closed', 'Office closed'],
    'italic star' => ['an *important* day', 'an important day'],
    'italic underscore' => ['an _important_ day', 'an important day'],
    'underline' => ['__note__ this', 'note this'],
    'strike' => ['~~old~~ new', 'old new'],
    'spoiler' => ['the answer is ||42||', 'the answer is ▒▒▒'],
    'inline code' => ['run `**npm**`', 'run **npm**'],
    'code block' => ["see\n```js\nconst a = 1;\n```", "see\nconst a = 1;"],
    'heading' => ['# Big news', 'Big news'],
    'quote' => ["> quoted\nreply", "quoted\nreply"],
    'escaped' => ['\\*not italic\\*', '*not italic*'],
    'plain stays' => ['2 * 3 * 4 and snake_case_name', '2 * 3 * 4 and snake_case_name'],
    'hashtag stays' => ['#general', '#general'],
]);
