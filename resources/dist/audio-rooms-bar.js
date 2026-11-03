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
    const HEADPHONES = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="5" height="7" rx="2"/><rect x="16" y="14" width="5" height="7" rx="2"/></svg>';
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
        // The app's accent colour (config 'branding'), like the room itself.
        const accent = holder.getAttribute('data-accent');
        if (accent) bar.style.setProperty('--car-bar-accent', accent);
        document.body.appendChild(bar);

        let info = null;
        let staleTimer = null;
        let message = null;      // short note in place of "You are in", e.g. microphone blocked
        let messageTimer = null;

        function say(text) {
            message = text;
            clearTimeout(messageTimer);
            messageTimer = setTimeout(() => { message = null; render(); }, 6000);
            render();
        }

        // The stylesheet is fetched the first time a bar is shown, so pages without a room pay nothing for it.
        // The bar appears once it has loaded (never unstyled).
        let styles = null;
        function loadStyles() {
            if (styles) return styles;
            const href = holder.getAttribute('data-css');
            styles = new Promise((resolve) => {
                if (!href || document.querySelector('link[href="' + href + '"]')) { resolve(); return; }
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.onload = () => resolve();
                link.onerror = () => resolve();
                document.head.appendChild(link);
            });
            styles.then(() => { styles.ready = true; render(); });
            return styles;
        }

        function render() {
            if (info && !(styles && styles.ready)) { loadStyles(); return; }
            if (!info) {
                bar.hidden = true;
                bar.innerHTML = '';
                document.documentElement.classList.remove('car-bar-open');
                return;
            }
            const mic = info.speaks
                ? '<button type="button" class="car-bar-btn' + (info.muted ? ' is-off' : ' is-live') + '" data-bar="mic" aria-pressed="' + !info.muted + '"' + (info.moderator_muted ? ' disabled' : '') + '>' +
                    (info.muted ? MIC_OFF : MIC) + '<span>' + esc(info.muted ? t.mic_off : t.mic_on) + '</span></button>'
                : '';
            const speaking = (info.speaking || []).filter(Boolean);
            if (info.speaking_self && !speaking.length) speaking.push('');
            const label = message || (!speaking.length ? t.in_room
                : speaking[0] === '' ? t.speaking_you
                : (speaking.length === 1 ? t.speaking_one : t.speaking_many).replace(':names', speaking.join(', ')));
            bar.innerHTML =
                '<span class="car-bar-icon' + (speaking.length ? ' is-speaking' : '') + '" aria-hidden="true">' + HEADPHONES + '<span class="car-bar-dot"></span></span>' +
                '<span class="car-bar-text"><span class="car-bar-label' + (message ? ' is-note' : (speaking.length ? ' is-speaking' : '')) + '">' + esc(label) + '</span>' +
                '<span class="car-bar-title">' + esc(info.title) + '</span></span>' +
                mic +
                '<button type="button" class="car-bar-btn car-bar-btn--primary" data-bar="back">' + esc(t.back) + '</button>';
            bar.hidden = false;
            document.documentElement.classList.add('car-bar-open');
        }

        channel.onmessage = (e) => {
            const msg = e.data || {};
            if (msg.type === 'room') {
                const first = !info;
                info = msg;
                if (first) watching();
                clearTimeout(staleTimer);
                staleTimer = setTimeout(() => { info = null; render(); }, STALE_MS);
                render();
            } else if (msg.type === 'mic-needs-room' && info && msg.room === info.room) {
                info.muted = true;
                say(t.mic_in_room);
            } else if (msg.type === 'left' && info && msg.room === info.room) {
                info = null;
                render();
            }
        };

        /**
         * Microphone permission belongs to the site, not the tab: when the room has not opened the microphone
         * yet, ask here (where the person is looking), so the room tab can switch it on without a hidden prompt.
         */
        async function toggleMic() {
            const room = info;
            if (room.muted && !room.mic_ready) {
                if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say(t.mic_in_room); return; }
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    stream.getTracks().forEach((track) => track.stop());
                } catch (err) {
                    say(t.mic_blocked);
                    return;
                }
                if (!info || info.room !== room.room) return;
            }
            // Shown at once; the room tab confirms with its next message.
            info.muted = !info.muted;
            render();
            channel.postMessage({ type: 'command', room: info.room, action: 'mic' });
        }

        /** The room carries on in this tab (the room page joins by itself and the other tab steps aside). */
        function continueHere() {
            const url = new URL(info.url, window.location.href);
            url.searchParams.set('continue', info.monitor ? 'monitor' : '1');
            window.location.href = url.toString();
        }

        /**
         * Browsers do not let a page bring just any tab to the front, but a tab opened from the room (links open
         * in a new tab while in a room) may bring forward the room tab that opened it. Where the browser refuses,
         * the room carries on in this tab instead.
         */
        function backToRoom() {
            let roomTab = null;
            try {
                const opener = window.opener;
                const path = (href) => new URL(href, window.location.href).pathname;
                if (opener && !opener.closed && opener.location.origin === window.location.origin
                    && path(opener.location.href) === path(info.url) && opener.document.querySelector('[data-audio-room]')) {
                    roomTab = opener;
                }
            } catch (err) { roomTab = null; }
            if (!roomTab) { continueHere(); return; }
            try { roomTab.focus(); } catch (err) { /* not allowed */ }
            setTimeout(() => {
                // Still the tab in front: the browser kept the focus here.
                if (!document.hidden && document.hasFocus()) continueHere();
            }, 400);
        }

        bar.addEventListener('click', (e) => {
            const button = e.target.closest('[data-bar]');
            if (!button || !info) return;
            if (button.getAttribute('data-bar') === 'mic') {
                toggleMic();
                return;
            }
            backToRoom();
        });

        // While this bar is on screen, the room tab keeps measuring who speaks (it saves battery otherwise).
        const watching = () => { if (info && !document.hidden) channel.postMessage({ type: 'watching', room: info.room }); };
        setInterval(watching, 4000);
        document.addEventListener('visibilitychange', watching);

        // A room tab that is already open answers straight away.
        channel.postMessage({ type: 'ping' });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
