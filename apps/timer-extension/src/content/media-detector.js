// media-detector.js — isolated world, document_idle, every frame.
//
// Tells the worker two booleans for this frame and nothing else:
//   videoPlaying — any <video> here is playing (not paused, not ended, has data)
//   streamLive   — stream-hook.js (MAIN world) reports a live mic/camera stream
//
// It reads NOTHING else from the page: no address, no page name, no text,
// no input. The only message it sends is {type: 'gt-media', videoPlaying, streamLive}.

(() => {
    let videoPlaying = false;
    let streamLive = false;

    /** Post the two booleans; ignore a worker that is unreachable or an extension that was reloaded. */
    function send() {
        try {
            const pending = chrome.runtime.sendMessage({ type: 'gt-media', videoPlaying, streamLive });
            if (pending && typeof pending.catch === 'function') {
                pending.catch(() => {});
            }
        } catch {
            // Extension context gone (reloaded or updated): nothing to tell.
        }
    }

    /** Any <video> in this frame that is actually playing. */
    function anyVideoPlaying() {
        for (const video of document.querySelectorAll('video')) {
            if (!video.paused && !video.ended && video.readyState >= 2) {
                return true;
            }
        }
        return false;
    }

    function updateVideo() {
        const now = anyVideoPlaying();
        if (now !== videoPlaying) {
            videoPlaying = now;
            send();
        }
    }

    // Media events do not bubble, but a capturing listener on document sees
    // them from every element, including ones added later. `playing` is
    // included because at `play` a video may not have data yet (readyState < 2).
    for (const type of ['play', 'playing', 'pause', 'ended', 'emptied']) {
        document.addEventListener(type, updateVideo, true);
    }

    // Live-stream reports from stream-hook.js in this same window.
    window.addEventListener('message', (event) => {
        if (event.source !== window) {
            return;
        }
        const data = event.data;
        if (!data || data.source !== 'gt-stream' || typeof data.live !== 'boolean') {
            return;
        }
        if (data.live !== streamLive) {
            streamLive = data.live;
            send();
        }
    });

    // Once on load.
    videoPlaying = anyVideoPlaying();
    send();
})();
