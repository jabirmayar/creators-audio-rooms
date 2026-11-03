/*!
 * creators-audio-rooms · room bar · MIT
 * "You are in a room" bar for the app's other pages: while a room is open in another tab of the same
 * browser, shows its title, a microphone button and "Back to room". Talks to the room tab over a
 * BroadcastChannel; nothing is sent to the server. Include with <x-creators-audio-rooms::room-bar />.
 */
(function () {
    'use strict';

    const MIC = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2.5" width="6" height="11.5" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7"/></svg>';
    const MIC_OFF = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M9 9v2a3 3 0 0 0 5.1 2.1M15 10.5V5.5a3 3 0 0 0-5.7-1.3"/><path d="M5.5 11a6.5 6.5 0 0 0 10.4 5.2M18.5 11c0 .8-.1 1.5-.4 2.2M12 17.5V21M8.5 21h7"/></svg>';
    // The room tab says it is still there every few seconds; after this long without a word the bar goes.
    const STALE_MS = 9000;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function start() {
        const holder = document.querySelector('[data-audio-rooms-bar]');
        // The room page runs the room itself.
        if (!holder || !('BroadcastChannel' in window) || document.querySelector('[data-audio-room]')) return;
        let t = {};
        try { t = JSON.parse(holder.getAttribute('data-audio-rooms-bar') || '{}'); } catch (e) { /* defaults */ }

        const channel = new BroadcastChannel('creators-audio-rooms');
        const bar = document.createElement('div');
        bar.className = 'car-bar';
        bar.hidden = true;
        bar.setAttribute('role', 'region');
        bar.setAttribute('aria-label', t.label || 'Audio room');
        document.body.appendChild(bar);

        let info = null;
        let staleTimer = null;

        function render() {
            if (!info) {
                bar.hidden = true;
                bar.innerHTML = '';
                document.documentElement.classList.remove('car-bar-open');
                return;
            }
            const mic = info.speaks && info.mic_ready
                ? '<button type="button" class="car-bar-btn' + (info.muted ? ' is-off' : ' is-live') + '" data-bar="mic" aria-pressed="' + !info.muted + '"' + (info.moderator_muted ? ' disabled' : '') + '>' +
                    (info.muted ? MIC_OFF : MIC) + '<span>' + esc(info.muted ? t.mic_off : t.mic_on) + '</span></button>'
                : '';
            bar.innerHTML =
                '<span class="car-bar-dot" aria-hidden="true"></span>' +
                '<span class="car-bar-text"><span class="car-bar-label">' + esc(t.in_room) + '</span>' +
                '<span class="car-bar-title">' + esc(info.title) + '</span></span>' +
                mic +
                '<button type="button" class="car-bar-btn car-bar-btn--primary" data-bar="back">' + esc(t.back) + '</button>';
            bar.hidden = false;
            document.documentElement.classList.add('car-bar-open');
        }

        channel.onmessage = (e) => {
            const msg = e.data || {};
            if (msg.type === 'room') {
                info = msg;
                clearTimeout(staleTimer);
                staleTimer = setTimeout(() => { info = null; render(); }, STALE_MS);
                render();
            } else if (msg.type === 'left' && info && msg.room === info.room) {
                info = null;
                render();
            }
        };

        bar.addEventListener('click', (e) => {
            const button = e.target.closest('[data-bar]');
            if (!button || !info) return;
            if (button.getAttribute('data-bar') === 'mic') {
                // Shown at once; the room tab confirms with its next message.
                info.muted = !info.muted;
                render();
                channel.postMessage({ type: 'command', room: info.room, action: 'mic' });
                return;
            }
            // Browsers do not let a page bring another tab to the front, so the room carries on in this tab
            // (the room page joins by itself and the other tab steps aside).
            const url = new URL(info.url, window.location.href);
            url.searchParams.set('continue', info.monitor ? 'monitor' : '1');
            window.location.href = url.toString();
        });

        // A room tab that is already open answers straight away.
        channel.postMessage({ type: 'ping' });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
