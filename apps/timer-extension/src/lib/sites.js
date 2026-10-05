// sites.js — which website (domain only) the person is on, and for how long.
//
// Pure helpers. The worker reads `tab.url` ONLY to derive the host here; the
// path, query, fragment and credentials are never kept (docs/extension-api.md §5).
//
// Tracking shape (stored as `sites` in chrome.storage.local, §9):
//   { open: { kind, host, since } | null,
//     closed: [ { kind, host, from, to } ] }
// `kind` is one of: site, other_app, browser_internal, private.

/** Schemes that are the browser's own pages, never a website. */
const INTERNAL_SCHEMES = [
    'chrome:',
    'chrome-extension:',
    'edge:',
    'about:',
    'file:',
    'devtools:',
    'chrome-untrusted:',
    'view-source:',
];

/** The most entries one Sample may carry (server rule: ≤ 20 sites). */
const MAX_ENTRIES = 20;

/** The most seconds one minute's sites may add up to (server rule: sum ≤ 60). */
const MAX_SECONDS = 60;

const IPV4 = /^\d{1,3}(?:\.\d{1,3}){3}$/;

/** Lower-case scheme of a URL string including the colon, e.g. "https:"; '' when none. */
function schemeOf(url) {
    const match = /^([a-z][a-z0-9+.-]*):/i.exec(url);
    return match ? match[1].toLowerCase() + ':' : '';
}

/**
 * The host of an http(s) URL as the server wants it: lower-case, one leading
 * "www." removed, ASCII (the URL parser already gives punycode), and ":port"
 * only for localhost or an IPv4 address with an explicit port.
 * Never a path, query, fragment or user:password.
 */
export function normaliseHost(url) {
    const parsed = new URL(url);
    let host = parsed.hostname.toLowerCase();
    if (host.startsWith('www.')) {
        host = host.slice(4);
    }
    if (parsed.port !== '' && (host === 'localhost' || IPV4.test(host))) {
        host += ':' + parsed.port;
    }
    return host;
}

/**
 * Classify a tab's URL into { kind, host }.
 * Incognito tabs are never recorded as a site — they are `private`.
 */
export function classifyUrl(url, { incognito } = {}) {
    if (incognito === true) {
        return { kind: 'private', host: '' };
    }
    if (typeof url !== 'string' || url === '') {
        return { kind: 'browser_internal', host: '' };
    }
    const scheme = schemeOf(url);
    if (INTERNAL_SCHEMES.includes(scheme)) {
        return { kind: 'browser_internal', host: '' };
    }
    if (scheme === 'http:' || scheme === 'https:') {
        let host = '';
        try {
            host = normaliseHost(url);
        } catch {
            // An unparsable address cannot yield a host; treat it as the browser's own.
            host = '';
        }
        if (host !== '') {
            return { kind: 'site', host };
        }
    }
    return { kind: 'browser_internal', host: '' };
}

/** Close the open segment at `now` (kept only when it lasted longer than zero). */
function closeOpen(tracking, now) {
    const closed = [...tracking.closed];
    const open = tracking.open;
    if (open && now > open.since) {
        closed.push({ kind: open.kind, host: open.host, from: open.since, to: now });
    }
    return closed;
}

/**
 * Pure reducer: returns a new tracking object.
 * Events: {type:'focus', kind, host} · {type:'unfocus'} (Chrome lost focus) · {type:'stop'}.
 */
export function applyEvent(tracking, event, now) {
    const current = {
        open: tracking?.open ?? null,
        closed: Array.isArray(tracking?.closed) ? tracking.closed : [],
    };

    if (event.type === 'stop') {
        return { open: null, closed: closeOpen(current, now) };
    }

    let next;
    if (event.type === 'focus') {
        next = { kind: event.kind, host: event.host ?? '' };
    } else if (event.type === 'unfocus') {
        next = { kind: 'other_app', host: '' };
    } else {
        return current;
    }

    // Same place as before: keep the open segment running.
    if (current.open && current.open.kind === next.kind && current.open.host === next.host) {
        return current;
    }

    return {
        open: { kind: next.kind, host: next.host, since: now },
        closed: closeOpen(current, now),
    };
}

/**
 * Per kind+host seconds spent inside the window [from, to).
 * Whole seconds, zeros dropped, longest first, at most 20 entries (the rest
 * folded into the same-kind host-less entry, or into `other_app`), and scaled
 * down proportionally when the total would exceed 60.
 * @returns {Array<{kind:string, host:string, seconds:number}>}
 */
export function summarise(tracking, from, to) {
    const segments = [...(tracking?.closed ?? [])];
    if (tracking?.open) {
        segments.push({ kind: tracking.open.kind, host: tracking.open.host, from: tracking.open.since, to });
    }

    // Sum the clipped milliseconds per kind+host.
    const totals = new Map();
    for (const seg of segments) {
        const start = Math.max(seg.from, from);
        const end = Math.min(seg.to, to);
        if (end <= start) {
            continue;
        }
        const key = seg.kind + '|' + seg.host;
        const entry = totals.get(key) ?? { kind: seg.kind, host: seg.host, ms: 0 };
        entry.ms += end - start;
        totals.set(key, entry);
    }

    let list = [...totals.values()]
        .map((e) => ({ kind: e.kind, host: e.host, seconds: Math.round(e.ms / 1000) }))
        .filter((e) => e.seconds > 0)
        .sort(bySecondsDesc);

    if (list.length > MAX_ENTRIES) {
        list = foldOverflow(list);
    }

    const sum = list.reduce((acc, e) => acc + e.seconds, 0);
    if (sum > MAX_SECONDS) {
        list = list
            .map((e) => ({ ...e, seconds: Math.floor((e.seconds * MAX_SECONDS) / sum) }))
            .filter((e) => e.seconds > 0)
            .sort(bySecondsDesc);
    }

    return list;
}

function bySecondsDesc(a, b) {
    return b.seconds - a.seconds;
}

/** Keep 20 entries; add the time of the rest to a host-less entry of the same kind, else to other_app. */
function foldOverflow(list) {
    const kept = list.slice(0, MAX_ENTRIES).map((e) => ({ ...e }));
    const rest = list.slice(MAX_ENTRIES);

    const sameKindTarget = (e) => (e.kind !== 'site' ? kept.find((k) => k.kind === e.kind && k.host === '') : undefined);

    // If anything has to go to other_app and there is none in the kept 20,
    // make room for one by folding the shortest kept entry too.
    const needsOtherApp = rest.some((e) => !sameKindTarget(e));
    if (needsOtherApp && !kept.some((k) => k.kind === 'other_app')) {
        rest.unshift(kept.pop());
        kept.push({ kind: 'other_app', host: '', seconds: 0 });
    }

    for (const e of rest) {
        const target = sameKindTarget(e) ?? kept.find((k) => k.kind === 'other_app');
        target.seconds += e.seconds;
    }

    return kept.filter((e) => e.seconds > 0).sort(bySecondsDesc);
}

/** Drop the closed segments that ended at or before `before` (their minute has been sent). */
export function rollWindow(tracking, before) {
    return {
        open: tracking?.open ?? null,
        closed: (tracking?.closed ?? []).filter((seg) => seg.to > before),
    };
}
