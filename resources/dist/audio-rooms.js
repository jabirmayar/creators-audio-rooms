/*!
 * creators-audio-rooms · MIT
 * Live audio rooms: peer-to-peer WebRTC audio, signaling over plain HTTP to the host app.
 * No dependencies and no build step. Mounts on every [data-audio-room] and [data-audio-rooms-create].
 */
(function () {
    'use strict';

    // ─── Small helpers ──────────────────────────────────────────────────────

    const svg = (body, fill) => '<svg viewBox="0 0 24 24" fill="' + (fill || 'none') + '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + body + '</svg>';
    const ICONS = {
        mic: svg('<rect x="9" y="2.5" width="6" height="11.5" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7"/>'),
        micOff: svg('<path d="M3 3l18 18"/><path d="M9 9v2a3 3 0 0 0 5.1 2.1M15 10.5V5.5a3 3 0 0 0-5.7-1.3"/><path d="M5.5 11a6.5 6.5 0 0 0 10.4 5.2M18.5 11c0 .8-.1 1.5-.4 2.2M12 17.5V21M8.5 21h7"/>'),
        hand: svg('<path d="M8 12.5V6a1.5 1.5 0 0 1 3 0v5.5"/><path d="M11 11V4.5a1.5 1.5 0 0 1 3 0V11"/><path d="M14 11.5V6a1.5 1.5 0 0 1 3 0v6"/><path d="M17 12V9.5a1.5 1.5 0 0 1 3 0V15a7 7 0 0 1-7 7h-1.2a6 6 0 0 1-4.5-2l-3.6-4a1.6 1.6 0 0 1 2.4-2.1L8 15.6V12.5"/>'),
        headphones: svg('<path d="M3.5 15.5V12a8.5 8.5 0 0 1 17 0v3.5"/><rect x="2.5" y="14" width="5" height="7" rx="2"/><rect x="16.5" y="14" width="5" height="7" rx="2"/>'),
        crown: svg('<path d="M3 8l4.5 4.5L12 5l4.5 7.5L21 8l-1.8 10.5H4.8z"/><path d="M5 21.5h14"/>'),
        star: svg('<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>'),
        users: svg('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20.5a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.6a6.5 6.5 0 0 1 3.5 5.9"/>'),
        clock: svg('<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>'),
        mail: svg('<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7.5l8.5 6 8.5-6"/>'),
        sparkle: svg('<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/>'),
        check: svg('<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>'),
        info: svg('<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/>'),
        alert: svg('<path d="M10.3 4.1L2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 4.1a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>'),
        end: svg('<rect x="4.5" y="4.5" width="15" height="15" rx="3"/>'),
        leave: svg('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>'),
        play: svg('<path d="M8 5v14l11-7z"/>', 'currentColor'),
        eye: svg('<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>'),
    };
    /** Animated "talking" bars shown on someone's face while they speak. */
    const SPEAKING_BARS = '<span class="car-icon-badge car-icon-badge--speaking" aria-hidden="true"><i></i><i></i><i></i></span>';

    const ROLE_SPEAKS = { host: true, co_host: true, speaker: true, listener: false, monitor: false };

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Only http(s) and same-site links are rendered; anything else (javascript:, data:) is dropped. */
    function safeUrl(url) {
        const value = String(url || '').trim();
        return /^(https?:\/\/|\/(?!\/))/i.test(value) ? value : null;
    }

    function cssEsc(value) {
        return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/["\\]/g, '\\$&');
    }

    let instances = 0;

    /** Stable soft colour for people without a photo (six hues). */
    function hue(key) {
        let n = 0;
        const text = String(key || '');
        for (let i = 0; i < text.length; i++) n += text.charCodeAt(i);
        return n % 6;
    }

    function avatarHtml(person, size, inner) {
        const img = safeUrl(person.avatar_url);
        return '<span class="car-avatar car-hue-' + hue(person.key || person.name) + (size ? ' car-avatar--' + size : '') + '">' +
            (img ? '<img src="' + esc(img) + '" alt="" loading="lazy">' : esc(initials(person.name))) + (inner || '') + '</span>';
    }

    function fill(text, replace) {
        let out = String(text || '');
        Object.keys(replace || {}).forEach((k) => { out = out.split(':' + k).join(replace[k]); });
        return out;
    }

    /** "Live for 12 min" / "Live for 1 h 5 min" / "Started just now". */
    function liveFor(startedAt, t) {
        const started = Date.parse(startedAt || '');
        if (!started) return '';
        const minutes = Math.max(0, Math.floor((Date.now() - started) / 60000));
        if (minutes < 1) return t.started_just_now || '';
        const duration = minutes < 60
            ? fill(t.duration_minutes, { count: minutes })
            : fill(t.duration_hours, { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
        return fill(t.live_for, { duration });
    }

    function initials(name) {
        const parts = String(name || '?').trim().split(/\s+/).slice(0, 2);
        return parts.map((p) => p.charAt(0).toUpperCase()).join('') || '?';
    }

    /** connectionState, falling back to iceConnectionState for browsers without it. */
    function linkState(pc) {
        if (pc.connectionState) return pc.connectionState;
        const ice = pc.iceConnectionState;
        return ice === 'completed' ? 'connected' : ice === 'checking' ? 'connecting' : ice;
    }

    function sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    class ApiError extends Error {
        constructor(status, body) {
            super((body && body.message) || 'Request failed');
            this.status = status;
            this.code = (body && body.error) || 'error';
            this.body = body || {};
        }
    }

    // ─── API client ─────────────────────────────────────────────────────────

    class Api {
        constructor(base, csrf) {
            this.base = base.replace(/\/$/, '');
            this.csrf = csrf;
            this.session = null;
        }

        async request(method, path, body, options) {
            const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (this.csrf) headers['X-CSRF-TOKEN'] = this.csrf;
            if (this.session) headers['X-Audio-Room-Session'] = this.session;
            if (body !== undefined) headers['Content-Type'] = 'application/json';

            const response = await fetch(this.base + path, {
                method,
                headers,
                body: body === undefined ? undefined : JSON.stringify(body),
                credentials: 'same-origin',
                keepalive: Boolean(options && options.keepalive),
                signal: options && options.signal,
            });

            let data = null;
            try { data = await response.json(); } catch (e) { data = null; }
            if (!response.ok) throw new ApiError(response.status, data);
            return data;
        }
    }

    // ─── One room ───────────────────────────────────────────────────────────

    class AudioRoom {
        constructor(el, config) {
            this.el = el;
            this.config = config;
            this.t = config.labels || {};
            this.api = new Api(config.api, config.csrf);

            this.state = null;        // last room state from the server
            this.joined = false;
            this.status = 'idle';     // idle | joining | connected | reconnecting | offline | replaced | removed | ended
            this.notice = null;       // { kind, text, action }
            this.cursor = 0;
            this.version = -1;
            this.iceServers = [];
            this.intervals = { active: 1200, idle: 2500, hidden: 5000 };
            this.lastActivity = 0;
            this.syncTimer = null;
            this.syncing = false;
            this.failures = 0;

            this.peers = new Map();   // key -> { pc, signature, audio, stream, analyser, pending: [], failed, polite }
            this.localStream = null;
            this.micStarting = false;
            this.audioContext = null;
            this.speaking = new Set();
            this.levelTimer = null;
            this.openMenu = null;
            this.menuJustOpened = false;
            this.audioBlocked = false;
            this.dialog = null;       // { title, confirm, danger, resolve }
            this.pendingFocus = null; // selector to focus after the next render
            this.knownRequests = null;
            this.requestAlertAt = 0;
            this.maxBitrate = 0;
            this.earlyCandidates = new Map(); // key -> { conn, list } received before the offer
            this.uid = 'car' + (++instances);

            this.el.classList.add('car-root');
            this.applyBranding();
            this.el.addEventListener('click', (e) => this.onClick(e));
            this.el.addEventListener('keydown', (e) => this.onKeydown(e));
            document.addEventListener('click', (e) => {
                // Ignore clicks on elements a re-render just replaced (e.g. the menu button itself).
                if (!e.target.isConnected) return;
                if (this.openMenu && !this.el.contains(e.target)) { this.openMenu = null; this.render(); }
            });

            this.bindLifecycle();
            this.clock = setInterval(() => {
                this.el.querySelectorAll('[data-live-for]').forEach((node) => { node.textContent = liveFor(node.getAttribute('data-live-for'), this.t); });
            }, 30000);
            this.load();
        }

        // ─── Text ──────────────────────────────────────────────────────────

        tr(key, replace) {
            let text = this.t[key] || key;
            Object.keys(replace || {}).forEach((k) => { text = text.split(':' + k).join(replace[k]); });
            return text;
        }

        applyBranding() {
            const b = this.config.branding || {};
            if (b.accent) this.el.style.setProperty('--car-accent', b.accent);
            if (b.accent_contrast) this.el.style.setProperty('--car-accent-contrast', b.accent_contrast);
            if (b.radius) this.el.style.setProperty('--car-radius', b.radius);
            if (b.theme && !this.el.hasAttribute('data-theme')) this.el.setAttribute('data-theme', b.theme);
            // Set through the CSSOM rather than a style attribute, so strict Content-Security-Policies allow it.
            if (this.el.getAttribute('data-height')) this.el.style.minHeight = this.el.getAttribute('data-height');
        }

        // ─── Loading & joining ─────────────────────────────────────────────

        async load() {
            if (!window.RTCPeerConnection) {
                this.notice = { kind: 'error', text: this.tr('webrtc_missing') };
            }
            try {
                this.setState(await this.api.request('GET', ''));
            } catch (e) {
                if (e instanceof ApiError && (e.status === 401 || e.status === 419)) { this.handleError(e); return; }
                this.notice = { kind: 'error', text: e.message || this.tr('error_generic') };
            }
            this.render();
            if (this.takeEnterFlag()) return;
            // Rooms that have not started yet are re-checked so listeners can join once live.
            this.scheduleLobbyRefresh();
        }

        /** The host just started this room: go straight in instead of showing "Enter as host". */
        takeEnterFlag() {
            let params;
            try { params = new URLSearchParams(window.location.search); } catch (e) { return false; }
            if (params.get('enter') !== '1') return false;
            params.delete('enter');
            const query = params.toString();
            try { window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash); } catch (e) { /* keep the URL */ }
            const me = this.state && this.state.me;
            if (!me || !['host', 'co_host'].includes(me.role) || this.state.room.status === 'ended' || !window.RTCPeerConnection) return false;
            this.join(false);
            return true;
        }

        scheduleLobbyRefresh() {
            clearTimeout(this.lobbyTimer);
            if (this.joined || !this.state || this.state.room.status === 'ended') return;
            this.lobbyTimer = setTimeout(async () => {
                if (this.joined) return;
                try { this.setState(await this.api.request('GET', '')); this.render(); } catch (e) { /* keep showing the previous state */ }
                this.scheduleLobbyRefresh();
            }, document.hidden ? 15000 : 6000);
        }

        async join(asMonitor) {
            if (this.status === 'joining') return;
            this.status = 'joining';
            this.notice = null;
            this.render();
            this.unlockAudio();

            try {
                this.asMonitor = Boolean(asMonitor);
                const data = await this.api.request('POST', '/join', this.joinBody());
                this.onJoined(data);
            } catch (e) {
                this.status = 'idle';
                this.handleError(e);
            }
        }

        /** Re-joins (after a lapsed session) use the same way the person first joined. */
        joinBody() {
            return this.asMonitor ? { as: 'monitor' } : {};
        }

        onJoined(data) {
            this.api.session = data.session;
            this.cursor = data.cursor || 0;
            this.iceServers = data.ice_servers || [];
            this.maxBitrate = (data.audio && data.audio.max_bitrate) || 0;
            this.intervals = Object.assign(this.intervals, data.intervals || {});
            this.joined = true;
            this.status = 'connected';
            this.failures = 0;
            this.closeAllPeers();
            this.setState(data.state);
            this.touchActivity();
            this.render();
            this.reconcilePeers();
            this.startLevelMeter();
            this.setMediaSession();
            this.scheduleSync(0);
            this.announce(this.tr('state_connected'));
        }

        async leave(silent) {
            const wasJoined = this.joined;
            this.stopSync();
            this.joined = false;
            this.status = 'idle';
            this.closeAllPeers();
            this.stopMic();
            this.speaking.clear();
            if (wasJoined) {
                try { await this.api.request('POST', '/leave', {}, { keepalive: true }); } catch (e) { /* leaving anyway */ }
            }
            this.api.session = null;
            if (!silent) {
                try { this.setState(await this.api.request('GET', '')); } catch (e) { /* ignore */ }
                this.render();
                this.scheduleLobbyRefresh();
            }
        }

        // ─── Sync loop (heartbeat + signaling + state) ─────────────────────

        scheduleSync(delay) {
            clearTimeout(this.syncTimer);
            if (!this.joined) return;
            this.syncTimer = setTimeout(() => this.sync(), delay == null ? this.nextInterval() : delay);
        }

        stopSync() {
            clearTimeout(this.syncTimer);
            this.syncTimer = null;
        }

        nextInterval() {
            if (document.hidden) return this.intervals.hidden;
            const recent = Date.now() - this.lastActivity < 10000;
            const negotiating = Array.from(this.peers.values()).some((p) => linkState(p.pc) !== 'connected');
            return recent || negotiating ? this.intervals.active : this.intervals.idle;
        }

        touchActivity() {
            this.lastActivity = Date.now();
        }

        async sync() {
            if (!this.joined || this.syncing) return;
            if (!navigator.onLine) {
                this.setStatus('offline');
                this.scheduleSync(3000);
                return;
            }
            this.syncing = true;
            try {
                const data = await this.api.request('GET', '/sync?since=' + this.cursor + '&v=' + this.version + (document.hidden ? '&hidden=1' : ''));
                this.failures = 0;
                if (this.status !== 'connected') this.setStatus('connected');
                this.cursor = data.cursor;
                if (data.renew_session) this.renewSession();
                if (data.state) {
                    this.touchActivity();
                    this.setState(data.state);
                    this.render();
                    this.reconcilePeers();
                }
                for (const message of data.messages || []) {
                    this.touchActivity();
                    await this.onSignal(message);
                }
            } catch (e) {
                await this.onSyncError(e);
            } finally {
                this.syncing = false;
                this.scheduleSync();
            }
        }

        /** Renew the room session token; this request also confirms the person is still signed in. */
        async renewSession() {
            if (this.renewing) return;
            this.renewing = true;
            try {
                const data = await this.api.request('GET', '/session');
                if (data && data.session) this.api.session = data.session;
            } catch (e) {
                if (e instanceof ApiError) this.handleError(e);
            } finally {
                this.renewing = false;
            }
        }

        async onSyncError(e) {
            if (!(e instanceof ApiError)) {
                // Network failure: keep the audio running and retry with backoff.
                this.failures++;
                this.setStatus(navigator.onLine ? 'reconnecting' : 'offline');
                await sleep(Math.min(8000, 500 * Math.pow(2, this.failures)));
                return;
            }
            if (e.code === 'session_expired' || e.code === 'not_a_member') {
                // Rejoin quietly: same member row, same role, new connection.
                try { this.onJoined(await this.api.request('POST', '/join', this.joinBody())); } catch (err) { this.handleError(err); }
                return;
            }
            if (e.code === 'too_many_requests') {
                await sleep(5000);
                return;
            }
            this.handleError(e);
        }

        /** Stop audio and syncing after a final answer from the server. */
        disconnect(status) {
            this.stopSync();
            this.joined = false;
            this.closeAllPeers();
            this.stopMic();
            this.dialog = null;
            this.status = status;
        }

        handleError(e) {
            const code = e && e.code;
            const terminal = { replaced: 'replaced', removed: 'removed', room_ended: 'ended', banned: 'removed' };
            if (terminal[code]) {
                if (e.body && e.body.state) this.setState(e.body.state);
                this.disconnect(terminal[code]);
                this.notice = { kind: code === 'room_ended' ? 'info' : 'warn', text: this.tr('state_' + terminal[code]) };
                this.render();
                return;
            }
            if ((code === 'session_expired' || code === 'not_a_member') && this.joined) {
                // The room session lapsed during an action: reconnect quietly.
                this.api.request('POST', '/join', this.joinBody()).then((data) => this.onJoined(data), (err) => {
                    if (err.code !== code) this.handleError(err);
                });
                return;
            }
            if (e && e.status === 401 && code !== 'session_expired') {
                // Signed out of the site elsewhere (or the login session expired).
                this.disconnect('signed_out');
                this.notice = { kind: 'warn', text: this.tr('signed_out'), action: 'sign_in' };
                this.render();
                return;
            }
            if (e && e.status === 419) {
                // The page's CSRF token expired (e.g. a tab left open overnight).
                this.notice = { kind: 'warn', text: this.tr('page_expired'), action: 'reload' };
                this.render();
                return;
            }
            if (code === 'room_not_live') {
                this.notice = { kind: 'info', text: this.tr('state_not_live') };
                this.render();
                this.scheduleLobbyRefresh();
                return;
            }
            this.notice = { kind: 'error', text: (e && e.message) || this.tr('error_generic') };
            this.render();
        }

        setStatus(status) {
            if (this.status === status) return;
            this.status = status;
            this.render();
            if (status === 'reconnecting' || status === 'offline') this.announce(this.tr('state_' + status));
        }

        setState(state) {
            if (!state) return;
            const before = this.state && this.state.me;
            this.state = state;
            this.version = state.room.version;
            this.onSelfChanged(before, state.me);
            this.onRequestsChanged(state);
            if (state.room.status === 'ended' && this.joined) {
                this.handleError({ code: 'room_ended', body: {} });
            }
        }

        // ─── My own role / mute changes ────────────────────────────────────

        onSelfChanged(before, me) {
            if (!me || !before || !this.joined) return;
            const couldSpeak = ROLE_SPEAKS[before.role];
            const canSpeak = ROLE_SPEAKS[me.role];

            if (!couldSpeak && canSpeak) this.announce(this.tr('became_speaker'), true);
            if (couldSpeak && !canSpeak) { this.stopMic(); this.announce(this.tr('became_listener'), true); }
            if (before.role !== 'host' && me.role === 'host') this.announce(this.tr('became_host'), true);

            if (!before.moderator_muted && me.moderator_muted) {
                // Stop sending immediately; only the participant can turn it back on.
                this.setTrackEnabled(false);
                this.announce(this.tr('you_were_muted'), true);
            }
            if (before.moderator_muted && !me.moderator_muted) this.announce(this.tr('unmute_allowed'), true);
            if (me.self_muted) this.setTrackEnabled(false);
            // The invitation card itself is visible; screen readers get it announced.
            if (!before.invited && me.invited) this.announce(this.tr('invitation_title'));
        }

        /** Tell moderators when someone new asks to speak (the queue may be scrolled out of view). */
        onRequestsChanged(state) {
            const requests = state.requests || [];
            if (!this.joined || !state.can.moderate) { this.knownRequests = null; return; }
            const known = this.knownRequests;
            this.knownRequests = new Set(requests.map((r) => r.key));
            if (!known) return;
            const fresh = requests.filter((r) => !known.has(r.key));
            if (!fresh.length) return;
            this.requestAlertAt = Date.now();
            this.announce(fresh.length === 1 ? this.tr('new_request', { name: fresh[0].name }) : this.tr('new_requests', { count: requests.length }), true);
        }

        // ─── Microphone ────────────────────────────────────────────────────

        async toggleMic() {
            const me = this.state && this.state.me;
            if (!me || !ROLE_SPEAKS[me.role]) return;
            if (!me.self_muted) {
                this.setTrackEnabled(false);
                await this.action('/mute', { muted: true });
                return;
            }
            if (me.moderator_muted) {
                this.notice = { kind: 'warn', text: this.t.muted_by_moderator || this.tr('muted_by_host') };
                this.render();
                return;
            }
            const ok = await this.ensureMic();
            if (!ok) return;
            const result = await this.action('/mute', { muted: false });
            if (result && result.me && !result.me.self_muted) this.setTrackEnabled(true);
        }

        async ensureMic() {
            if (this.localStream && this.localStream.getAudioTracks().some((t) => t.readyState === 'live')) return true;
            if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.notice = { kind: 'error', text: this.tr('mic_insecure') };
                this.render();
                return false;
            }
            this.micStarting = true;
            this.render();
            try {
                this.localStream = await navigator.mediaDevices.getUserMedia({
                    audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                    video: false,
                });
                const track = this.localStream.getAudioTracks()[0];
                track.enabled = false;
                track.addEventListener('ended', () => {
                    // Unplugged headset or OS revoked access.
                    this.localStream = null;
                    this.action('/mute', { muted: true });
                    this.notice = { kind: 'warn', text: this.tr('mic_failed') };
                    this.render();
                });
                this.attachLocalTrack();
                this.meterStream('__me', this.localStream);
                return true;
            } catch (e) {
                const map = { NotAllowedError: 'mic_denied', SecurityError: 'mic_denied', NotFoundError: 'mic_missing', OverconstrainedError: 'mic_missing', NotReadableError: 'mic_busy', AbortError: 'mic_busy' };
                this.notice = { kind: 'error', text: this.tr(map[e && e.name] || 'mic_failed') };
                return false;
            } finally {
                this.micStarting = false;
                this.render();
            }
        }

        stopMic() {
            if (this.localStream) {
                this.localStream.getTracks().forEach((t) => t.stop());
                this.localStream = null;
            }
            this.peers.forEach((peer) => {
                peer.pc.getTransceivers().forEach((tr) => {
                    if (tr.sender && tr.sender.track) tr.sender.replaceTrack(null).catch(() => {});
                });
            });
        }

        setTrackEnabled(enabled) {
            if (!this.localStream) return;
            this.localStream.getAudioTracks().forEach((t) => { t.enabled = enabled; });
        }

        localTrack() {
            return this.localStream ? this.localStream.getAudioTracks()[0] || null : null;
        }

        attachLocalTrack() {
            const track = this.localTrack();
            this.peers.forEach((peer) => {
                const tr = peer.pc.getTransceivers()[0];
                if (tr && (tr.direction === 'sendrecv' || tr.direction === 'sendonly')) {
                    tr.sender.replaceTrack(track).then(() => this.applyBitrate(peer)).catch(() => {});
                }
            });
        }

        /** Cap upload per link: a speaker sends one copy per member, so this keeps phones' uplinks small. */
        applyBitrate(peer) {
            if (!this.maxBitrate) return;
            peer.pc.getSenders().forEach((sender) => {
                if (!sender.track || !sender.getParameters || !sender.setParameters) return;
                try {
                    const params = sender.getParameters();
                    if (!params.encodings || !params.encodings.length) return; // not negotiated yet
                    if (params.encodings[0].maxBitrate === this.maxBitrate) return;
                    params.encodings[0].maxBitrate = this.maxBitrate;
                    sender.setParameters(params).catch(() => {});
                } catch (e) { /* optional: some browsers do not allow changing it */ }
            });
        }

        // ─── Peer connections ──────────────────────────────────────────────

        members() {
            if (!this.state) return [];
            return [].concat(this.state.hosts || [], this.state.speakers || [], this.state.listeners || [], this.state.monitors || []);
        }

        /**
         * Connect to everyone where at least one side speaks. The speaker starts;
         * between two speakers the smaller key starts. Role changes rebuild the link.
         */
        reconcilePeers() {
            if (!this.joined || !this.state || !this.state.me) return;
            const me = this.state.me;
            const iSpeak = ROLE_SPEAKS[me.role];
            const wanted = new Map();

            this.members().forEach((m) => {
                if (m.key === me.key) return;
                const theySpeak = ROLE_SPEAKS[m.role];
                if (!iSpeak && !theySpeak) return;
                wanted.set(m.key, { member: m, signature: (iSpeak ? 'S' : 'L') + (theySpeak ? 'S' : 'L'), iStart: iSpeak && (!theySpeak || me.key < m.key) });
            });

            this.peers.forEach((peer, key) => {
                const want = wanted.get(key);
                const reconnected = want && peer.conn && want.member.conn && peer.conn !== want.member.conn;
                if (!want || want.signature !== peer.signature) this.closePeer(key, true);
                else if (reconnected) this.closePeer(key, false);
            });

            wanted.forEach((want, key) => {
                if (!this.peers.has(key) && want.iStart) this.startPeer(key, want.signature, want.member.conn);
                this.applyRemoteMute(key, want.member);
            });
        }

        newPeer(key, signature) {
            const pc = new RTCPeerConnection({ iceServers: this.iceServers });
            const peer = { pc, signature, audio: null, stream: null, pending: [], outbox: [], outboxTimer: null, restarts: 0, remoteSet: false };
            this.peers.set(key, peer);

            pc.addEventListener('icecandidate', (e) => {
                if (!e.candidate) return;
                // Candidates arrive in bursts; send them together instead of one request each.
                peer.outbox.push(e.candidate.toJSON());
                if (!peer.outboxTimer) peer.outboxTimer = setTimeout(() => this.flushOutbox(key, peer), 150);
            });
            pc.addEventListener('track', (e) => this.onRemoteTrack(key, e));
            pc.addEventListener('connectionstatechange', () => this.onPeerState(key));
            pc.addEventListener('iceconnectionstatechange', () => this.onPeerState(key));
            return peer;
        }

        async startPeer(key, signature, conn) {
            const peer = this.newPeer(key, signature);
            peer.conn = conn || null;
            const theySpeak = signature.charAt(1) === 'S';
            const init = { direction: theySpeak ? 'sendrecv' : 'sendonly' };
            if (this.maxBitrate) init.sendEncodings = [{ maxBitrate: this.maxBitrate }];
            let tr;
            try { tr = peer.pc.addTransceiver('audio', init); } catch (e) { delete init.sendEncodings; tr = peer.pc.addTransceiver('audio', init); }
            const track = this.localTrack();
            if (track) await tr.sender.replaceTrack(track).catch(() => {});
            await this.sendOffer(key, peer);
        }

        async sendOffer(key, peer, iceRestart) {
            try {
                const offer = await peer.pc.createOffer(iceRestart ? { iceRestart: true } : undefined);
                await peer.pc.setLocalDescription(offer);
                await this.signal(key, 'offer', { type: offer.type, sdp: offer.sdp });
            } catch (e) {
                this.closePeer(key, false);
            }
        }

        async onSignal(message) {
            const key = message.from;
            let payload = null;
            try { payload = JSON.parse(message.payload); } catch (e) { return; }
            const me = this.state && this.state.me;
            if (!me) return;

            if (message.type === 'bye') {
                // Only close the link the sender closed; a newer link may already exist.
                const existing = this.peers.get(key);
                const theirs = payload && payload.sig ? payload.sig.split('').reverse().join('') : null;
                if (existing && (!theirs || existing.signature === theirs)) this.closePeer(key, false);
                return;
            }

            if (message.type === 'offer') {
                const member = this.members().find((m) => m.key === key);
                if (!member) return;
                const signature = (ROLE_SPEAKS[me.role] ? 'S' : 'L') + (ROLE_SPEAKS[member.role] ? 'S' : 'L');
                let peer = this.peers.get(key);
                // A new offer from a new connection (they reconnected) replaces the old link.
                if (peer && (peer.conn !== message.conn || peer.signature !== signature)) { this.closePeer(key, false); peer = null; }
                if (!peer) peer = this.newPeer(key, signature);
                peer.conn = message.conn;
                const early = this.earlyCandidates.get(key);
                this.earlyCandidates.delete(key);
                if (early && early.conn === message.conn) peer.pending.push(...early.list);
                try {
                    await peer.pc.setRemoteDescription(payload);
                    peer.remoteSet = true;
                    const tr = peer.pc.getTransceivers()[0];
                    if (tr) {
                        if (ROLE_SPEAKS[me.role]) {
                            tr.direction = 'sendrecv';
                            const track = this.localTrack();
                            if (track) await tr.sender.replaceTrack(track).catch(() => {});
                        } else {
                            // Listeners only ever receive.
                            tr.direction = 'recvonly';
                        }
                    }
                    const answer = await peer.pc.createAnswer();
                    await peer.pc.setLocalDescription(answer);
                    await this.flushCandidates(peer);
                    await this.signal(key, 'answer', { type: answer.type, sdp: answer.sdp });
                } catch (e) {
                    this.closePeer(key, false);
                }
                return;
            }

            const candidates = message.type === 'candidates' ? (Array.isArray(payload) ? payload : [])
                : message.type === 'candidate' ? [payload] : null;
            const peer = this.peers.get(key);
            if (!peer) {
                // Candidates can overtake the offer they belong to; keep them briefly.
                if (candidates) {
                    const early = this.earlyCandidates.get(key);
                    const list = early && early.conn === message.conn ? early.list : [];
                    this.earlyCandidates.set(key, { conn: message.conn, list: list.concat(candidates).slice(-60) });
                }
                return;
            }
            if (message.type === 'answer') {
                if (peer.pc.signalingState !== 'have-local-offer') return;
                if (peer.conn && message.conn && peer.conn !== message.conn) return;
                peer.conn = message.conn;
                try {
                    await peer.pc.setRemoteDescription(payload);
                    peer.remoteSet = true;
                    await this.flushCandidates(peer);
                } catch (e) { this.closePeer(key, false); }
                return;
            }
            if (candidates) {
                if (peer.conn && message.conn && peer.conn !== message.conn) return;
                if (!peer.remoteSet) { peer.pending.push(...candidates); return; }
                for (const candidate of candidates) {
                    try { await peer.pc.addIceCandidate(candidate); } catch (e) { /* stale candidate */ }
                }
            }
        }

        flushOutbox(key, peer) {
            peer.outboxTimer = null;
            if (this.peers.get(key) !== peer || !peer.outbox.length) return;
            this.signal(key, 'candidates', peer.outbox.splice(0, 30));
            if (peer.outbox.length) peer.outboxTimer = setTimeout(() => this.flushOutbox(key, peer), 50);
        }

        async flushCandidates(peer) {
            const pending = peer.pending.splice(0);
            for (const candidate of pending) {
                try { await peer.pc.addIceCandidate(candidate); } catch (e) { /* stale candidate */ }
            }
        }

        onPeerState(key) {
            const peer = this.peers.get(key);
            if (!peer) return;
            const state = linkState(peer.pc);
            if (state === 'connected') { peer.restarts = 0; peer.failed = false; this.applyBitrate(peer); this.render(); return; }
            if (state !== 'failed') return;

            const iStart = peer.pc.localDescription && peer.pc.localDescription.type === 'offer';
            if (iStart && peer.restarts < 2) {
                peer.restarts++;
                this.sendOffer(key, peer, true);
                return;
            }
            peer.failed = true;
            const member = this.members().find((m) => m.key === key);
            this.notice = { kind: 'warn', text: this.tr('cannot_reach', { name: member ? member.name : key }) };
            this.render();
        }

        onRemoteTrack(key, event) {
            const peer = this.peers.get(key);
            if (!peer) return;
            const stream = (event.streams && event.streams[0]) || new MediaStream([event.track]);
            peer.stream = stream;
            if (!peer.audio) {
                peer.audio = document.createElement('audio');
                peer.audio.autoplay = true;
                peer.audio.setAttribute('playsinline', '');
                peer.audio.hidden = true;
                this.el.appendChild(peer.audio);
            }
            peer.audio.srcObject = stream;
            this.applyRemoteMute(key, this.members().find((m) => m.key === key));
            this.play(peer.audio);
            this.meterStream(key, stream);
        }

        /** Receiver-side enforcement: never play someone who is not allowed to speak. */
        applyRemoteMute(key, member) {
            const peer = this.peers.get(key);
            if (!peer || !peer.audio) return;
            peer.audio.muted = !member || !ROLE_SPEAKS[member.role] || member.moderator_muted;
        }

        play(audio) {
            const attempt = audio.play();
            if (attempt && attempt.catch) {
                attempt.catch(() => {
                    this.audioBlocked = true;
                    this.render();
                });
            }
        }

        unlockAudio() {
            // Called from a tap so the browser allows playback.
            if (!this.audioContext) {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (Ctx) this.audioContext = new Ctx();
            }
            if (this.audioContext && this.audioContext.state !== 'running') this.audioContext.resume().catch(() => {});
            if (this.audioContext && !this.audioContext.onstatechange) {
                // iOS marks the context "interrupted" during calls; resume when it comes back.
                this.audioContext.onstatechange = () => {
                    if (this.joined && this.audioContext.state !== 'running' && !document.hidden) this.audioContext.resume().catch(() => {});
                };
            }
            this.peers.forEach((peer) => { if (peer.audio) peer.audio.play().catch(() => {}); });
            this.audioBlocked = false;
            this.setMediaSession();
        }

        /** Lock-screen / notification info; also helps mobile browsers keep audio playing in the background. */
        setMediaSession() {
            if (!('mediaSession' in navigator) || !this.state || typeof window.MediaMetadata !== 'function') return;
            try {
                navigator.mediaSession.metadata = new window.MediaMetadata({
                    title: this.state.room.title,
                    artist: (this.config.branding && this.config.branding.name) || '',
                });
                navigator.mediaSession.playbackState = this.joined ? 'playing' : 'none';
            } catch (e) { /* optional */ }
        }

        closePeer(key, notify) {
            const peer = this.peers.get(key);
            if (!peer) return;
            this.peers.delete(key);
            clearTimeout(peer.outboxTimer);
            try { peer.pc.close(); } catch (e) { /* already closed */ }
            if (peer.audio) { peer.audio.srcObject = null; peer.audio.remove(); }
            if (peer.analyser) peer.analyser.source.disconnect();
            this.speaking.delete(key);
            if (notify && this.joined) this.signal(key, 'bye', { sig: peer.signature });
        }

        closeAllPeers() {
            Array.from(this.peers.keys()).forEach((key) => this.closePeer(key, false));
        }

        async signal(to, type, payload) {
            try {
                await this.api.request('POST', '/signal', { to, type, payload: JSON.stringify(payload) });
            } catch (e) {
                if (e instanceof ApiError && ['replaced', 'removed', 'room_ended', 'session_expired'].includes(e.code)) {
                    this.onSyncError(e);
                }
            }
        }

        // ─── Speaking indicators ───────────────────────────────────────────

        meterStream(key, stream) {
            if (!this.audioContext) return;
            try {
                const source = this.audioContext.createMediaStreamSource(stream);
                const analyser = this.audioContext.createAnalyser();
                analyser.fftSize = 512;
                source.connect(analyser);
                const target = key === '__me' ? this : this.peers.get(key);
                if (target) target.analyser = { source, analyser, data: new Uint8Array(analyser.fftSize) };
            } catch (e) { /* metering is optional */ }
        }

        /** Audio level reported by WebRTC itself for a remote speaker (0–1), or 0 if unknown/stale. */
        remoteLevel(peer) {
            let best = 0;
            peer.pc.getReceivers().forEach((receiver) => {
                if (!receiver.getSynchronizationSources) return;
                receiver.getSynchronizationSources().forEach((source) => {
                    const fresh = source.timestamp == null || performance.now() - source.timestamp < 1000 || Date.now() - source.timestamp < 1000;
                    if (fresh && typeof source.audioLevel === 'number') best = Math.max(best, source.audioLevel);
                });
            });
            return best;
        }

        /**
         * Average level of a remote speaker since the previous check, from the receiver's
         * energy counters. Unlike a snapshot it also catches short sounds between checks.
         */
        async energyLevel(peer) {
            const receiver = peer.pc.getReceivers()[0];
            if (peer.energyPending || !receiver || !receiver.getStats) return 0;
            peer.energyPending = true;
            try {
                let current = null;
                (await receiver.getStats()).forEach((r) => {
                    if (r.type === 'inbound-rtp' && typeof r.totalAudioEnergy === 'number') current = { energy: r.totalAudioEnergy, duration: r.totalSamplesDuration };
                });
                const previous = peer.energy;
                peer.energy = current;
                if (!current || !previous || !(current.duration > previous.duration)) return 0;
                return Math.sqrt(Math.max(0, current.energy - previous.energy) / (current.duration - previous.duration));
            } catch (e) {
                return 0;
            } finally {
                peer.energyPending = false;
            }
        }

        startLevelMeter() {
            clearInterval(this.levelTimer);
            this.heardAt = new Map(); // key -> last time sound was detected
            const HOLD_MS = 600;       // keeps the ring steady between words
            const rms = (meter) => {
                meter.analyser.getByteTimeDomainData(meter.data);
                let sum = 0;
                for (let i = 0; i < meter.data.length; i++) { const v = (meter.data[i] - 128) / 128; sum += v * v; }
                return Math.sqrt(sum / meter.data.length);
            };
            this.levelTimer = setInterval(() => {
                if (document.hidden) return;
                const me = this.state && this.state.me;
                const now = Date.now();
                this.peers.forEach((peer, key) => {
                    if (!peer.audio || peer.audio.muted) return;
                    if (this.remoteLevel(peer) > 0.02 || (peer.analyser && rms(peer.analyser) > 0.04)) this.heardAt.set(key, now);
                    // Result lands before the next tick.
                    this.energyLevel(peer).then((level) => { if (level > 0.02) this.heardAt.set(key, Date.now()); });
                });
                if (me && this.analyser && !me.self_muted && rms(this.analyser) > 0.04) this.heardAt.set(me.key, now);

                const next = new Set();
                this.heardAt.forEach((at, key) => {
                    const peer = this.peers.get(key);
                    const audible = me && key === me.key ? !me.self_muted : Boolean(peer && peer.audio && !peer.audio.muted);
                    if (audible && now - at < HOLD_MS) next.add(key);
                    else if (now - at >= HOLD_MS) this.heardAt.delete(key);
                });

                const changed = next.size !== this.speaking.size || Array.from(next).some((k) => !this.speaking.has(k));
                if (!changed) return;
                this.speaking = next;
                this.el.querySelectorAll('[data-person]').forEach((node) => {
                    node.classList.toggle('is-speaking', next.has(node.getAttribute('data-person')));
                });
            }, 200);
        }

        // ─── Browser lifecycle ─────────────────────────────────────────────

        bindLifecycle() {
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && this.joined) {
                    // Back on the page: catch up now and resume audio if the browser paused it.
                    if (this.audioContext && this.audioContext.state === 'suspended') this.audioContext.resume().catch(() => {});
                    this.peers.forEach((peer) => { if (peer.audio && peer.audio.paused) this.play(peer.audio); });
                    this.scheduleSync(0);
                }
            });
            window.addEventListener('online', () => { if (this.joined) this.scheduleSync(0); });
            window.addEventListener('offline', () => { if (this.joined) this.setStatus('offline'); });
            window.addEventListener('pagehide', (e) => {
                if (this.joined && !e.persisted) {
                    this.api.request('POST', '/leave', {}, { keepalive: true }).catch(() => {});
                }
            });
        }

        // ─── Actions ───────────────────────────────────────────────────────

        async action(path, body) {
            this.touchActivity();
            try {
                const state = await this.api.request('POST', path, body || {});
                this.setState(state);
                this.render();
                this.reconcilePeers();
                return state;
            } catch (e) {
                this.handleError(e);
                return null;
            }
        }

        async onClick(e) {
            if (this.audioBlocked || (this.audioContext && this.audioContext.state !== 'running')) this.unlockAudio();
            if (this.dialog && e.target.hasAttribute && e.target.hasAttribute('data-dialog-backdrop')) { this.closeDialog(false); return; }
            const button = e.target.closest('[data-action]');
            if (!button || !this.el.contains(button)) return;
            const action = button.getAttribute('data-action');
            const target = button.getAttribute('data-target');
            const name = button.getAttribute('data-name') || '';

            switch (action) {
                case 'join': return this.join(false);
                case 'join-monitor': return this.join(true);
                case 'leave':
                    if (await this.confirmLeave()) await this.leave(false);
                    return;
                case 'start':
                    this.unlockAudio();
                    return this.action('/start');
                case 'end':
                    if (await this.confirm(this.tr('end_confirm'), this.tr('end'))) await this.action('/end');
                    return;
                case 'mic': return this.toggleMic();
                case 'hand': {
                    const raised = Boolean(this.state.me && this.state.me.hand_raised);
                    const ok = raised
                        ? await this.confirm(this.tr('lower_hand_confirm'), this.tr('lower_hand'), { tone: 'primary', cancelLabel: this.tr('keep_request') })
                        : await this.confirm(this.tr('raise_hand_confirm'), this.tr('raise_hand'), { tone: 'primary', body: this.tr('raise_hand_confirm_body') });
                    if (ok) await this.action('/hand', { raised: !raised });
                    return;
                }
                case 'invite-accept': return this.action('/invitation', { accept: true });
                case 'invite-decline': return this.action('/invitation', { accept: false });
                case 'show-requests': {
                    const first = this.el.querySelector('#' + this.uid + '-req [data-action="approve_speaker"]');
                    if (first) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); first.focus({ preventScroll: true }); }
                    return;
                }
                case 'unlock-audio': this.unlockAudio(); this.render(); return;
                case 'reload': window.location.reload(); return;
                case 'dismiss': this.notice = null; this.render(); return;
                case 'dialog-confirm': return this.closeDialog(true);
                case 'dialog-cancel': return this.closeDialog(false);
                case 'menu':
                    this.openMenu = this.openMenu === target ? null : target;
                    this.menuJustOpened = Boolean(this.openMenu);
                    this.render();
                    return;
                case 'remove':
                case 'ban':
                    this.closeMenu();
                    if (!(await this.confirm(this.tr(action + '_confirm', { name }), this.tr(action)))) return;
                    return this.action('/moderate', { action, target });
                default:
                    if (['approve_speaker', 'invite_speaker', 'cancel_invite', 'decline_request', 'make_co_host', 'demote', 'mute', 'allow_unmute'].includes(action)) {
                        this.closeMenu();
                        return this.action('/moderate', { action, target });
                    }
            }
        }

        /** Explains what leaving means for a host (handover, or the room ending without one). */
        confirmLeave() {
            const s = this.state;
            const me = s && s.me;
            if (!this.joined || !me) return Promise.resolve(true);
            let body = '';
            let tone = 'primary';
            if (me.role === 'host') {
                const next = (s.hosts || []).find((m) => m.role === 'co_host' && m.key !== me.key);
                if (next) body = this.tr('leave_confirm_handover', { name: next.name });
                else { body = this.tr('leave_confirm_host'); tone = 'danger'; }
            }
            return this.confirm(this.tr('leave_confirm'), this.tr('leave'), { tone, body });
        }

        /** Close the member menu and return focus to the person it was opened for. */
        closeMenu() {
            if (!this.openMenu) return;
            this.pendingFocus = '[data-action="menu"][data-target="' + cssEsc(this.openMenu) + '"]';
            this.openMenu = null;
            this.render();
        }

        // ─── Confirmation dialog (replaces window.confirm) ─────────────────

        /** options: { tone: 'danger' | 'primary', body, cancelLabel } */
        confirm(title, confirmLabel, options) {
            if (this.dialog) this.dialog.resolve(false);
            const opts = options || {};
            return new Promise((resolve) => {
                const active = document.activeElement;
                this.dialog = { title, confirmLabel, body: opts.body || '', tone: opts.tone || 'danger', cancelLabel: opts.cancelLabel || '', resolve, returnTo: active && this.el.contains(active) ? active : null };
                this.pendingFocus = '[data-action="dialog-cancel"]';
                this.render();
            });
        }

        closeDialog(result) {
            const dialog = this.dialog;
            if (!dialog) return;
            this.dialog = null;
            this.render();
            if (dialog.returnTo && dialog.returnTo.isConnected) dialog.returnTo.focus({ preventScroll: true });
            dialog.resolve(result);
        }

        renderDialog() {
            if (!this.dialog) return '';
            const id = this.uid + '-dialog';
            return '<div class="car-dialog-backdrop" data-dialog-backdrop><div class="car-dialog" role="alertdialog" aria-modal="true" aria-labelledby="' + id + '">' +
                '<p class="car-dialog-title" id="' + id + '">' + esc(this.dialog.title) + '</p>' +
                (this.dialog.body ? '<p class="car-dialog-body">' + esc(this.dialog.body) + '</p>' : '') +
                '<div class="car-dialog-actions">' +
                    '<button type="button" class="car-btn" data-action="dialog-cancel">' + esc(this.dialog.cancelLabel || this.tr('cancel')) + '</button>' +
                    '<button type="button" class="car-btn ' + (this.dialog.tone === 'primary' ? 'car-btn--primary' : 'car-btn--danger-solid') + '" data-action="dialog-confirm">' + esc(this.dialog.confirmLabel) + '</button>' +
                '</div></div></div>';
        }

        // ─── Keyboard ──────────────────────────────────────────────────────

        onKeydown(e) {
            if (this.dialog) {
                if (e.key === 'Escape') { e.preventDefault(); this.closeDialog(false); return; }
                if (e.key === 'Tab') {
                    // Keep focus inside the dialog.
                    const buttons = Array.from(this.el.querySelectorAll('.car-dialog button'));
                    const i = buttons.indexOf(document.activeElement);
                    const next = e.shiftKey ? (i <= 0 ? buttons.length - 1 : i - 1) : (i + 1) % buttons.length;
                    e.preventDefault();
                    if (buttons[next]) buttons[next].focus();
                }
                return;
            }
            if (!this.openMenu) return;
            if (e.key === 'Escape') { e.preventDefault(); this.closeMenu(); return; }

            const menu = this.el.querySelector('.car-menu');
            if (!menu) return;
            if (e.key === 'Tab') { this.closeMenu(); return; } // focus moves on from the menu button
            const items = Array.from(menu.querySelectorAll('[role="menuitem"]'));
            if (!items.length) return;
            const i = items.indexOf(document.activeElement);
            const move = { ArrowDown: (i + 1) % items.length, ArrowUp: (i <= 0 ? items.length : i) - 1, Home: 0, End: items.length - 1 }[e.key];
            if (move === undefined) return;
            e.preventDefault();
            items[move].focus();
        }

        announce(text, visible) {
            const live = this.el.querySelector('[data-live]');
            if (live) live.textContent = text;
            // Visible messages never hide an error the participant has not dismissed.
            if (visible && !(this.notice && this.notice.kind === 'error')) { this.notice = { kind: 'ok', text }; this.render(); }
        }

        // ─── Rendering ─────────────────────────────────────────────────────

        /** Selector for the focused control, so focus survives a re-render. */
        focusedSelector() {
            const active = document.activeElement;
            if (!active || !this.el.contains(active) || active === this.el) return null;
            const action = active.getAttribute('data-action');
            if (!action) return null;
            const target = active.getAttribute('data-target');
            return '[data-action="' + cssEsc(action) + '"]' + (target ? '[data-target="' + cssEsc(target) + '"]' : '');
        }

        render() {
            if (!this.state) {
                this.el.innerHTML = this.notice ? this.renderBanner()
                    : '<div class="car-loading" role="status">' + esc(this.tr('state_connecting')) + '</div>';
                this.el.innerHTML += this.liveRegion();
                return;
            }
            const focus = this.pendingFocus || this.focusedSelector();
            this.pendingFocus = null;
            const s = this.state;
            const room = s.room;
            const statusBadge = room.status === 'live'
                ? '<span class="car-badge car-badge--live">' + esc(this.tr('live')) + '</span>'
                : '<span class="car-badge">' + esc(this.tr(room.status === 'ended' ? 'ended' : 'scheduled')) + '</span>';

            const meta = [];
            if (s.host) meta.push('<span class="car-meta-host">' + avatarHtml(s.host, 'xs') + esc(this.tr('hosted_by', { name: s.host.name })) + '</span>');
            if (room.status === 'live') {
                meta.push('<span class="car-meta-item">' + ICONS.clock + '<span data-live-for="' + esc(room.started_at || '') + '">' + esc(liveFor(room.started_at, this.t)) + '</span></span>');
                meta.push('<span class="car-meta-item">' + ICONS.users + esc(this.tr('people_count', { count: s.counts.people })) + '</span>');
            }
            if (room.audience_label) meta.push('<span>' + esc(room.audience_label) + '</span>');

            const audio = Array.from(this.el.querySelectorAll('audio'));
            // The same live-region node is kept, so screen readers neither miss nor repeat announcements.
            const live = this.el.querySelector('[data-live]');
            const opening = this.menuJustOpened;
            this.el.innerHTML =
                '<header class="car-header"><div class="car-header-top"><div class="car-header-main">' +
                    '<h2 class="car-title">' + esc(room.title) + '</h2>' +
                    (meta.length ? '<div class="car-meta car-meta--dots">' + meta.join('') + '</div>' : '') +
                '</div>' + statusBadge + '</div>' +
                (room.description ? '<p class="car-description">' + esc(room.description) + '</p>' : '') +
                this.renderMonitorNote() +
                '</header>' +
                this.renderBanner() +
                this.renderInvitation() +
                '<div class="car-body">' + this.renderSections() + '</div>' +
                this.renderControls() +
                (live ? '' : this.liveRegion()) +
                this.renderDialog();
            // Keep playing audio elements across re-renders.
            if (live) this.el.appendChild(live);
            audio.forEach((a) => this.el.appendChild(a));
            this.el.querySelectorAll('[data-person]').forEach((node) => {
                node.classList.toggle('is-speaking', this.speaking.has(node.getAttribute('data-person')));
            });
            this.placeMenu();
            if (focus && !opening) {
                const node = this.el.querySelector(focus);
                if (node) node.focus({ preventScroll: true });
            }
        }

        /** Keep an open member menu on screen (flip it toward the other edge near the side of the page). */
        placeMenu() {
            const menu = this.el.querySelector('.car-menu');
            if (!menu) return;
            const rect = menu.getBoundingClientRect();
            const rtl = window.getComputedStyle(this.el).direction === 'rtl';
            if (rtl ? rect.left < 8 : rect.right > window.innerWidth - 8) menu.classList.add('car-menu--flip');
            if (rect.bottom > window.innerHeight - 8 && rect.height < menu.parentElement.getBoundingClientRect().top - 8) menu.classList.add('car-menu--up');
            if (this.menuJustOpened) {
                // Focus the first item only when the menu opens, not on every refresh.
                this.menuJustOpened = false;
                const first = menu.querySelector('[role="menuitem"]');
                if (first) first.focus({ preventScroll: true });
            }
        }

        liveRegion() {
            return '<div class="car-sr" aria-live="polite" data-live></div>';
        }

        renderBanner() {
            const parts = [];
            const status = {
                reconnecting: ['warn', 'state_reconnecting'], offline: ['warn', 'state_offline'],
            }[this.status];
            if (status) parts.push(this.banner(status[0], this.tr(status[1])));
            if (this.audioBlocked && this.joined) {
                parts.push(this.banner('warn', this.tr('audio_blocked'), '<button type="button" class="car-btn car-btn--small car-btn--primary" data-action="unlock-audio">' + ICONS.play + esc(this.tr('audio_blocked')) + '</button>'));
            }
            if (this.notice) {
                const actions = [];
                if (this.notice.action === 'reload') {
                    actions.push('<button type="button" class="car-btn car-btn--small car-btn--primary" data-action="reload">' + esc(this.tr('refresh')) + '</button>');
                }
                const login = safeUrl(this.config.login_url);
                if (this.notice.action === 'sign_in' && login) {
                    actions.push('<a class="car-btn car-btn--small car-btn--primary" href="' + esc(login) + '">' + esc(this.tr('sign_in')) + '</a>');
                }
                actions.push('<button type="button" class="car-btn car-btn--small" data-action="dismiss" aria-label="' + esc(this.tr('dismiss')) + '">×</button>');
                parts.push(this.banner(this.notice.kind, this.notice.text, '<div class="car-banner-actions">' + actions.join('') + '</div>'));
            }
            return parts.join('');
        }

        /** "Monitored by …": monitoring is never hidden from the room. */
        renderMonitorNote() {
            const monitors = this.state.monitors || [];
            if (!monitors.length) return '';
            const names = monitors.slice(0, 2).map((m) => (this.state.me && m.key === this.state.me.key) ? this.tr('you') : m.name);
            if (monitors.length > 2) names.push('+' + (monitors.length - 2));
            return '<p class="car-monitor-note">' + ICONS.eye + '<span>' + esc(this.tr('monitored_by', { names: names.join(', ') })) + '</span></p>';
        }

        /** Shown to a listener a host invited to speak; nothing changes until they accept. */
        renderInvitation() {
            const me = this.state.me;
            if (!this.joined || !me || !me.invited || me.role !== 'listener') return '';
            const id = this.uid + '-invite';
            return '<div class="car-invite" role="group" aria-labelledby="' + id + '">' +
                '<span class="car-invite-icon">' + ICONS.mail + '</span><div class="car-invite-text"><p class="car-invite-title" id="' + id + '">' + esc(this.tr('invitation_title')) + '</p>' +
                '<p class="car-invite-body">' + esc(this.tr('invitation_body')) + '</p></div>' +
                '<div class="car-invite-actions">' +
                    '<button type="button" class="car-btn car-btn--small" data-action="invite-decline">' + esc(this.tr('not_now')) + '</button>' +
                    '<button type="button" class="car-btn car-btn--small car-btn--primary" data-action="invite-accept">' + ICONS.mic + esc(this.tr('accept')) + '</button>' +
                '</div></div>';
        }

        banner(kind, text, extra) {
            const icon = { ok: ICONS.check, info: ICONS.info, warn: ICONS.alert, error: ICONS.alert }[kind] || ICONS.info;
            return '<div class="car-banner car-banner--' + esc(kind) + '" role="' + (kind === 'error' ? 'alert' : 'status') + '"><span class="car-banner-icon">' + icon + '</span><span>' + esc(text) + '</span>' + (extra || '') + '</div>';
        }

        renderSections() {
            const s = this.state;
            const html = [];

            if (s.can.moderate && (s.requests || []).length) {
                const id = this.uid + '-req';
                html.push('<section class="car-section" id="' + id + '" aria-labelledby="' + id + '-title"><h3 class="car-section-title" id="' + id + '-title">' + ICONS.hand + esc(this.tr('requests')) + ' <span class="car-count">' + s.requests.length + '</span></h3><div class="car-requests">' +
                    s.requests.map((m) => '<div class="car-request">' + avatarHtml(m, 'sm') + '<span class="car-request-name">' + esc(m.name) + '</span>' +
                        '<button type="button" class="car-btn car-btn--small car-btn--primary" data-action="approve_speaker" data-target="' + esc(m.key) + '">' + esc(this.tr('approve')) + '</button>' +
                        '<button type="button" class="car-btn car-btn--small" data-action="decline_request" data-target="' + esc(m.key) + '">' + esc(this.tr('decline')) + '</button></div>').join('') +
                    '</div></section>');
            }

            // Everyone who can speak shares one stage, hosts first.
            const stage = [].concat(s.hosts || [], s.speakers || []);
            const stageId = this.uid + '-stage';
            html.push('<section class="car-section car-stage" aria-labelledby="' + stageId + '"><h3 class="car-section-title" id="' + stageId + '">' + ICONS.mic + esc(this.tr('speakers')) +
                ' <span class="car-count">' + stage.length + '</span></h3>' +
                (stage.length
                    ? '<div class="car-grid car-grid--stage">' + stage.map((m) => this.person(m, 'stage')).join('') + '</div>'
                    : '<p class="car-empty">' + esc(this.tr(s.room.status === 'live' ? 'empty_stage' : 'waiting_for_host')) + '</p>') +
                '</section>');

            const listeners = s.listeners || [];
            const listId = this.uid + '-listeners';
            html.push('<section class="car-section" aria-labelledby="' + listId + '"><h3 class="car-section-title" id="' + listId + '">' + ICONS.headphones + esc(this.tr('listeners')) +
                ' <span class="car-count">' + listeners.length + '</span></h3>' +
                (listeners.length
                    ? '<div class="car-grid car-grid--listeners">' + listeners.map((m) => this.person(m, 'compact')).join('') + '</div>'
                    : '<p class="car-empty">' + esc(this.tr('empty_listeners')) + '</p>') +
                '</section>');
            return html.join('');
        }

        person(m, variant) {
            const s = this.state;
            const me = s.me;
            const isMe = me && m.key === me.key;
            const speaks = ROLE_SPEAKS[m.role];
            const roleLabel = m.role === 'host' || m.role === 'co_host' ? this.tr(m.role) : '';
            const badges = [];
            if (speaks && (m.self_muted || m.moderator_muted)) {
                badges.push('<span class="car-icon-badge car-icon-badge--' + (m.moderator_muted ? 'danger' : 'muted') + '" title="' + esc(this.tr(m.moderator_muted ? 'muted_by_host' : 'muted')) + '">' + ICONS.micOff + '</span>');
            } else if (speaks) {
                badges.push(SPEAKING_BARS);
            }
            if (m.hand_raised) badges.push('<span class="car-icon-badge car-icon-badge--hand" title="' + esc(this.tr('hand_raised')) + '">' + ICONS.hand + '</span>');
            else if (m.invited) badges.push('<span class="car-icon-badge car-icon-badge--invite" title="' + esc(this.tr('invited_badge')) + '">' + ICONS.mail + '</span>');

            const canAct = s.can.moderate && !isMe && m.role !== 'monitor' && !(m.role === 'host' && !['host', 'monitor'].includes(me.role));
            const states = [m.hand_raised ? this.tr('hand_raised') : (m.invited ? this.tr('invited_badge') : '')].filter(Boolean);
            const label = esc(m.name) + (isMe ? ' (' + esc(this.tr('you')) + ')' : '') + (roleLabel ? ', ' + esc(roleLabel) : '') + (states.length ? ', ' + esc(states.join(', ')) : '');
            // Moderators open the member menu from the card (it has a "View profile" item); others get a plain link.
            const profile = !canAct && !isMe ? safeUrl(m.profile_url) : null;
            const nameText = esc(isMe ? this.tr('you') : m.name);

            let html = '<div class="car-person car-person--' + (variant || 'stage') + (m.connected === false ? ' is-disconnected' : '') + (m.invited ? ' is-invited' : '') + '" data-person="' + esc(m.key) + '">' +
                avatarHtml(m, variant === 'compact' ? 'md' : 'lg', badges.join('')) +
                (profile
                    ? '<a class="car-person-name" href="' + esc(profile) + '" target="_blank" rel="noopener noreferrer" title="' + esc(this.tr('view_profile')) + ': ' + esc(m.name) + '">' + nameText + '</a>'
                    : '<span class="car-person-name" title="' + esc(m.name) + '">' + nameText + '</span>') +
                (roleLabel ? '<span class="car-role-chip' + (m.role === 'host' ? ' car-role-chip--host' : '') + '">' + (m.role === 'host' ? ICONS.crown : ICONS.star) + esc(roleLabel) + '</span>' : '');

            if (canAct) {
                html += '<button type="button" class="car-person-menu" data-action="menu" data-target="' + esc(m.key) + '" aria-haspopup="true" aria-expanded="' + (this.openMenu === m.key) + '" aria-label="' + esc(this.tr('actions_for', { name: m.name })) + '"></button>';
                if (this.openMenu === m.key) html += this.menu(m);
            } else {
                html = html.replace('class="car-person', 'aria-label="' + label + '" class="car-person');
            }
            return html + '</div>';
        }

        menu(m) {
            const s = this.state;
            const labels = { approve_speaker: 'approve', invite_speaker: 'invite' };
            const btn = (action, cls) => '<button type="button" role="menuitem" tabindex="-1"' + (cls ? ' class="' + cls + '"' : '') + ' data-action="' + action + '" data-target="' + esc(m.key) + '" data-name="' + esc(m.name) + '">' + esc(this.tr(labels[action] || action)) + '</button>';
            const items = [];
            const profile = safeUrl(m.profile_url);
            if (profile) items.push('<a role="menuitem" tabindex="-1" href="' + esc(profile) + '" target="_blank" rel="noopener noreferrer">' + esc(this.tr('view_profile')) + '</a><div class="car-menu-sep" role="separator"></div>');
            if (m.role === 'listener') {
                // A raised hand is consent, so approving promotes; otherwise the listener gets an invitation to accept.
                if (m.invited) items.push(btn('cancel_invite'));
                else if (s.counts.voices < s.room.limits.speakers) items.push(btn(m.hand_raised ? 'approve_speaker' : 'invite_speaker'));
                if (m.hand_raised) items.push(btn('decline_request'));
            }
            const actingAsMonitor = s.me && s.me.role === 'monitor';
            if (ROLE_SPEAKS[m.role] && (m.role !== 'host' || actingAsMonitor)) {
                if (!m.moderator_muted) items.push(btn('mute')); else items.push(btn('allow_unmute'));
            }
            if (ROLE_SPEAKS[m.role] && m.role !== 'host') items.push(btn('demote'));
            if (m.role !== 'co_host' && m.role !== 'host' && s.counts.hosts < s.room.limits.hosts) items.push(btn('make_co_host'));
            items.push('<div class="car-menu-sep" role="separator"></div>');
            items.push(btn('remove', 'is-danger'));
            items.push(btn('ban', 'is-danger'));
            return '<div class="car-menu" role="menu" aria-label="' + esc(this.tr('actions_for', { name: m.name })) + '"><div class="car-menu-title" aria-hidden="true">' + esc(m.name) + '</div>' + items.join('') + '</div>';
        }

        renderControls() {
            const s = this.state;
            const me = s.me;
            const room = s.room;
            const out = [];

            if (!this.joined) {
                if (room.status === 'ended' || ['removed', 'replaced', 'signed_out'].includes(this.status)) return '';
                const isHost = me && (me.role === 'host' || me.role === 'co_host');
                // Without WebRTC there is nothing to join; the notice explains why.
                const canMonitor = Boolean(s.can.monitor);
                if (!window.RTCPeerConnection || !(room.status === 'live' || isHost || canMonitor)) return '';
                const busy = this.status === 'joining';
                // People who already hold a role (or monitor) always get back in; listener places can run out.
                const full = !isHost && !(me && ['speaker', 'monitor'].includes(me.role)) && s.counts.listeners >= room.limits.listeners;
                if (room.status === 'live' || isHost) {
                    out.push('<button type="button" class="car-btn car-btn--primary car-btn--wide" data-action="join"' + (busy || full ? ' disabled' : '') + '>' + ICONS.headphones +
                        esc(busy ? this.tr('joining') : (room.status === 'live' ? this.tr('join') : this.tr('join_as_host'))) + '</button>');
                }
                if (canMonitor && !isHost) {
                    out.push('<button type="button" class="car-btn' + (full || room.status !== 'live' ? ' car-btn--primary' : '') + '" data-action="join-monitor"' + (busy ? ' disabled' : '') + '>' + ICONS.eye + esc(this.tr('join_as_monitor')) + '</button>');
                }
                const hints = [];
                if (full) hints.push(this.tr('room_full_hint'));
                if (canMonitor && !isHost) hints.push(this.tr('monitor_hint'));
                else if (!isHost && !full) hints.push(this.tr('join_hint'));
                // Phones may pause audio in the background; only worth saying on touch devices.
                const touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
                if (touch && this.config.background_note !== false) hints.push(this.tr('background_note'));
                if (hints.length) out.push('<p class="car-join-hint">' + hints.map(esc).join(' ') + '</p>');
                return '<div class="car-controls car-controls--join">' + out.join('') + '</div>';
            }

            if (me && ROLE_SPEAKS[me.role]) {
                const live = !me.self_muted;
                out.push('<button type="button" class="car-btn ' + (live ? 'car-btn--primary' : 'car-btn--mic-off') + '" data-action="mic" aria-pressed="' + live + '"' + (this.micStarting ? ' disabled' : '') + '>' +
                    (live ? ICONS.mic : ICONS.micOff) + '<span>' + esc(this.micStarting ? this.tr('mic_starting') : this.tr(live ? 'mic_on' : 'mic_off')) + '</span></button>');
            } else if (s.can.request_to_speak) {
                out.push('<button type="button" class="car-btn car-btn--hand' + (me.hand_raised ? ' car-btn--primary is-raised' : '') + '" data-action="hand" aria-pressed="' + Boolean(me.hand_raised) + '">' + ICONS.hand +
                    '<span>' + esc(this.tr(me.hand_raised ? 'lower_hand' : 'raise_hand')) + '</span></button>');
            }
            if (me && me.role === 'monitor') out.push('<span class="car-chip car-chip--monitor">' + ICONS.eye + esc(this.tr('monitoring')) + '</span>');
            if (s.can.start) out.push('<button type="button" class="car-btn car-btn--primary" data-action="start">' + esc(this.tr('start')) + '</button>');
            const waiting = (s.requests || []).length;
            if (s.can.moderate && waiting) {
                const nudge = Date.now() - this.requestAlertAt < 1500 ? ' is-attention' : '';
                out.push('<button type="button" class="car-btn' + nudge + '" data-action="show-requests" aria-label="' + esc(this.tr('new_requests', { count: waiting })) + '">' + ICONS.hand +
                    '<span class="car-btn-label--hide-sm">' + esc(this.tr('requests')) + '</span><span class="car-btn-count">' + waiting + '</span></button>');
            }

            out.push('<span class="car-controls-spacer"></span>');
            if (s.can.end) out.push('<button type="button" class="car-btn car-btn--danger" data-action="end" aria-label="' + esc(this.tr('end')) + '">' + ICONS.end + '<span class="car-btn-label--hide-sm">' + esc(this.tr('end')) + '</span></button>');
            out.push('<button type="button" class="car-btn" data-action="leave">' + ICONS.leave + '<span class="car-btn-label--hide-sm">' + esc(this.tr('leave')) + '</span></button>');
            return '<div class="car-controls" role="toolbar" aria-label="' + esc(this.tr('rooms')) + '">' + out.join('') + '</div>';
        }
    }

    // ─── Create-room form (room list page) ──────────────────────────────────

    function mountCreateForm(form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const error = form.querySelector('[data-error]');
            const button = form.querySelector('button[type="submit"]');
            if (error) error.textContent = '';
            button.disabled = true;
            try {
                const api = new Api(form.getAttribute('action'), form.getAttribute('data-csrf'));
                const data = await api.request('POST', '', {
                    title: form.elements.title.value,
                    description: form.elements.description ? form.elements.description.value : null,
                    start: true,
                });
                if (data.url) window.location.href = data.url + (data.url.indexOf('?') === -1 ? '?' : '&') + 'enter=1';
            } catch (err) {
                const errors = err.body && err.body.errors;
                if (error) error.textContent = errors ? Object.values(errors).flat()[0] : err.message;
                button.disabled = false;
            }
        });
    }

    // ─── Room list (refreshes in the background) ────────────────────────────

    function mountRoomList(el) {
        let config;
        try { config = JSON.parse(el.getAttribute('data-audio-rooms-list')); } catch (e) { return; }
        const t = config.labels || {};
        const api = new Api(config.api, null);
        let last = null;

        const card = (room) => {
            const href = safeUrl(room.url);
            const live = room.status === 'live';
            const top = [live
                ? '<span class="car-badge car-badge--live">' + esc(t.live) + '</span><span class="car-card-time">' + ICONS.clock + '<span data-live-for="' + esc(room.started_at || '') + '">' + esc(liveFor(room.started_at, t)) + '</span></span>'
                : '<span class="car-badge">' + esc(t.scheduled) + '</span>'];
            if (room.is_mine) top.push('<span class="car-chip">' + esc(t.yours) + '</span>');

            const stage = room.stage || [];
            const extra = Math.max(0, (room.stage_count || 0) - stage.length);
            const stack = live && stage.length
                ? '<span class="car-stack" aria-hidden="true">' + stage.map((p) => avatarHtml(p, 'sm')).join('') + (extra ? '<span class="car-stack-more">+' + extra + '</span>' : '') + '</span>'
                : '';
            const host = room.host ? '<span class="car-card-host">' + avatarHtml(room.host, 'xs') + '<span>' + esc(fill(t.hosted_by, { name: room.host.name })) + '</span></span>' : '';
            const action = live ? t.join_short : (room.is_mine ? t.open_room : t.view_room);
            const people = live ? '<span class="car-card-people">' + ICONS.users + esc(fill(t.people_count, { count: room.people })) + '</span>' : '';

            return '<article class="car-card' + (live ? ' is-live' : '') + '">' +
                '<div class="car-card-top">' + top.join('') + '</div>' +
                '<h3 class="car-card-title">' + (href ? '<a class="car-card-link" href="' + esc(href) + '">' + esc(room.title) + '</a>' : esc(room.title)) + '</h3>' +
                (room.description ? '<p class="car-card-description">' + esc(room.description) + '</p>' : '') +
                (room.audience_label ? '<p class="car-card-audience">' + esc(room.audience_label) + '</p>' : '') +
                host +
                '<div class="car-card-foot">' + stack + people +
                    (href ? '<a class="car-btn car-btn--small' + (live ? ' car-btn--primary' : '') + '" href="' + esc(href) + '" tabindex="-1" aria-hidden="true">' + esc(action) + '</a>' : '') +
                '</div></article>';
        };

        const render = (rooms) => {
            const html = rooms.length
                ? '<div class="car-cards">' + rooms.map(card).join('') + '</div>'
                : '<div class="car-empty-state"><span class="car-empty-icon">' + ICONS.mic + '</span><p class="car-empty-title">' + esc(t.no_rooms) + '</p><p class="car-empty-hint">' + esc(t.no_rooms_hint) + '</p></div>';
            // Skip identical updates so focus and hover are not disturbed.
            if (html !== last) { last = html; el.innerHTML = html; }
        };

        const refresh = async () => {
            if (document.hidden) return;
            try { render((await api.request('GET', '')).rooms || []); } catch (e) { /* keep the current list */ }
        };

        render(config.rooms || []);
        setInterval(refresh, 15000);
        setInterval(() => {
            el.querySelectorAll('[data-live-for]').forEach((node) => { node.textContent = liveFor(node.getAttribute('data-live-for'), t); });
        }, 30000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    }

    // ─── "Live now" strip (home pages, dashboards) ──────────────────────────

    function mountLiveNow(el) {
        let config;
        try { config = JSON.parse(el.getAttribute('data-audio-rooms-live')); } catch (e) { return; }
        const t = config.labels || {};
        const api = new Api(config.api, null);
        let last = null;

        const item = (room) => {
            const href = safeUrl(room.url);
            const faces = (room.stage || []).slice(0, 3);
            const sub = [room.host ? fill(t.hosted_by, { name: room.host.name }) : '', fill(t.people_count, { count: room.people })].filter(Boolean).join(' · ');
            const tag = href ? 'a' : 'div';
            return '<' + tag + (href ? ' href="' + esc(href) + '"' : '') + ' class="car-live-item">' +
                (faces.length ? '<span class="car-stack" aria-hidden="true">' + faces.map((p) => avatarHtml(p, 'sm')).join('') + '</span>' : '') +
                '<span class="car-live-text"><span class="car-live-title">' + esc(room.title) + '</span><span class="car-live-sub">' + esc(sub) + '</span></span>' +
                '<span class="car-btn car-btn--small car-btn--primary car-live-listen" aria-hidden="true">' + ICONS.headphones + '<span>' + esc(t.listen) + '</span></span>' +
                '</' + tag + '>';
        };

        const render = (rooms) => {
            // The room already open on this page is not advertised again.
            const here = window.location.pathname.replace(/\/$/, '');
            // Phones show fewer, so the strip never pushes the page far down.
            const narrow = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
            const max = narrow ? Math.min(config.limit || 6, 3) : (config.limit || 6);
            const live = rooms.filter((r) => r.status === 'live' && !(r.url && safeUrl(r.url) && new URL(r.url, window.location.href).pathname.replace(/\/$/, '') === here)).slice(0, max);
            const allLive = rooms.filter((r) => r.status === 'live').length;
            const more = safeUrl(config.all_url) && allLive > live.length
                ? '<a class="car-live-more" href="' + esc(safeUrl(config.all_url)) + '">' + esc(fill(t.see_all, { count: allLive })) + '</a>'
                : '';
            const html = live.length
                ? '<div class="car-live-strip"><div class="car-live-head"><span class="car-live-label"><i></i>' + esc(t.live_now) + '</span>' + more + '</div><div class="car-live-items">' + live.map(item).join('') + '</div></div>'
                : '';
            if (html === last) return;
            last = html;
            el.innerHTML = html;
            el.hidden = !live.length;
        };

        let latest = config.rooms || [];
        const show = (rooms) => { latest = rooms; render(rooms); };
        show(latest);
        if (window.matchMedia) {
            const query = window.matchMedia('(max-width: 640px)');
            const redraw = () => render(latest);
            if (query.addEventListener) query.addEventListener('change', redraw); else if (query.addListener) query.addListener(redraw);
        }
        const refresh = async () => {
            if (document.hidden) return;
            try { show((await api.request('GET', '')).rooms || []); } catch (e) { /* keep what is shown */ }
        };
        setInterval(refresh, 20000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    }

    function mountAll() {
        document.querySelectorAll('[data-audio-room]:not([data-car-mounted])').forEach((el) => {
            el.setAttribute('data-car-mounted', '');
            try {
                el.audioRoom = new AudioRoom(el, JSON.parse(el.getAttribute('data-audio-room')));
            } catch (e) {
                el.textContent = 'Audio room could not start.';
            }
        });
        document.querySelectorAll('[data-audio-rooms-create]:not([data-car-mounted])').forEach((form) => {
            form.setAttribute('data-car-mounted', '');
            mountCreateForm(form);
        });
        document.querySelectorAll('[data-audio-rooms-live]:not([data-car-mounted])').forEach((strip) => {
            strip.setAttribute('data-car-mounted', '');
            mountLiveNow(strip);
        });
        document.querySelectorAll('[data-audio-rooms-list]:not([data-car-mounted])').forEach((list) => {
            list.setAttribute('data-car-mounted', '');
            mountRoomList(list);
        });
    }

    window.CreatorsAudioRooms = { mount: mountAll, AudioRoom };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll);
    else mountAll();
})();
