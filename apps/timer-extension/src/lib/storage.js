// storage.js — thin promise wrappers over chrome.storage.local.
//
// The service worker keeps NO state in globals (it dies after ~30 s idle);
// every value lives in chrome.storage.local in the shape of
// docs/extension-api.md §9. Tests inject a fake `storage` object with the same
// get/set/remove API.

/** The §9 shape with its starting values. */
export const DEFAULTS = Object.freeze({
    server: 'https://erp.goodtechies.com',
    token: null,
    device: null,
    install_uuid: null,
    state: null,
    state_at: 0,
    lastInputAt: 0,
    lastExemptAt: 0,
    lockedAt: null,
    manualMeetingUntil: null,
    sites: { open: null, closed: [] },
    frames: {},
    queue: {},
    prompt: null,
});

/** chrome.storage.local, unless a test passes its own. */
function area(storage) {
    return storage ?? globalThis.chrome.storage.local;
}

/** Read keys (string, array, object of defaults, or null for everything). */
export function read(keys, storage) {
    return area(storage).get(keys);
}

/** Write an object of key → value. */
export function write(obj, storage) {
    return area(storage).set(obj);
}

/** Remove one key or an array of keys. */
export function remove(keys, storage) {
    return area(storage).remove(keys);
}
