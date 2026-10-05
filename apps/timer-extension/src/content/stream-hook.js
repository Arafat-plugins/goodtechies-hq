// stream-hook.js — runs in the page's MAIN world at document_start, every frame.
//
// It notices whether this frame holds a live microphone/camera stream (a call
// in Google Meet, a web call app, …). It wraps getUserMedia only: screen
// sharing without a mic (getDisplayMedia) is not a call, so it is not wrapped.
//
// A muted microphone in a joined call is still a "live" track, so it still
// counts as a call.
//
// It must not use chrome.* (the MAIN world has no extension APIs). It only
// ever posts {source: 'gt-stream', live: boolean} to its own window, where
// media-detector.js (isolated world) picks it up. It reads nothing else.

(() => {
    const mediaDevices = navigator.mediaDevices;
    if (!mediaDevices || typeof mediaDevices.getUserMedia !== 'function') {
        return;
    }

    // Tracks handed out by getUserMedia in this frame that may still be live.
    const tracks = new Set();
    let lastLive = false;

    /** Recompute `live`; post it when it changed. */
    function evaluate() {
        for (const track of tracks) {
            if (track.readyState !== 'live') {
                tracks.delete(track);
            }
        }
        const live = tracks.size > 0;
        if (live !== lastLive) {
            lastLive = live;
            window.postMessage({ source: 'gt-stream', live }, '*');
        }
    }

    /** Remember every audio/video track of a stream and watch for its end. */
    function watch(stream) {
        if (!stream || typeof stream.getTracks !== 'function') {
            return;
        }
        for (const track of stream.getTracks()) {
            if (track.kind !== 'audio' && track.kind !== 'video') {
                continue;
            }
            tracks.add(track);
            track.addEventListener('ended', evaluate);
        }
        evaluate();
    }

    // Wrap getUserMedia on the prototype so every MediaDevices object is covered.
    const target = typeof MediaDevices !== 'undefined' && MediaDevices.prototype.getUserMedia
        ? MediaDevices.prototype
        : mediaDevices;
    const originalGetUserMedia = target.getUserMedia;
    target.getUserMedia = function getUserMedia(...args) {
        return originalGetUserMedia.apply(this, args).then((stream) => {
            try {
                watch(stream);
            } catch {
                // Never break the page's call because of the hook.
            }
            return stream;
        });
    };

    // track.stop() does not fire `ended`, so re-evaluate after every stop.
    if (typeof MediaStreamTrack !== 'undefined' && typeof MediaStreamTrack.prototype.stop === 'function') {
        const originalStop = MediaStreamTrack.prototype.stop;
        MediaStreamTrack.prototype.stop = function stop(...args) {
            const result = originalStop.apply(this, args);
            try {
                if (tracks.has(this)) {
                    evaluate();
                }
            } catch {
                // Ignore: the hook must never break the page.
            }
            return result;
        };
    }
})();
