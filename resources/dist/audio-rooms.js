/*!
 * creators-audio-rooms · MIT
 * Live audio rooms: peer-to-peer WebRTC audio, signaling over plain HTTP to the host app.
 * No dependencies and no build step. Mounts on every [data-audio-room] and [data-audio-rooms-create].
 */
(function () {
    'use strict';

    // ─── Small helpers ──────────────────────────────────────────────────────

    const ICONS = {
        mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5"/></svg>',
        micOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 2l20 20M9 9v1a3 3 0 0 0 5.1 2.1M15 9.3V5a3 3 0 0 0-5.9-.6"/><path d="M17 16.9A7 7 0 0 1 5 10M19 10a7 7 0 0 1-.1 1.2M12 17v5"/></svg>',
        hand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 11V6a2 2 0 0 0-4 0v5M14 10V4a2 2 0 0 0-4 0v6M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.9-6-2.4l-3.6-3.6a2 2 0 0 1 2.8-2.8L7 15"/></svg>',
        leave: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
        play: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
    };

    const ROLE_SPEAKS = { host: true, co_host: true, speaker: true, listener: false };

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
            // Rooms that have not started yet are re-checked so listeners can join once live.
            this.scheduleLobbyRefresh();
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

        async join() {
            if (this.status === 'joining') return;
            this.status = 'joining';
            this.notice = null;
            this.render();
            this.unlockAudio();

            try {
                const data = await this.api.request('POST', '/join', {});
                this.onJoined(data);
            } catch (e) {
                this.status = 'idle';
                this.handleError(e);
            }
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
                try { this.onJoined(await this.api.request('POST', '/join', {})); } catch (err) { this.handleError(err); }
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
                this.api.request('POST', '/join', {}).then((data) => this.onJoined(data), (err) => {
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
            return [].concat(this.state.hosts || [], this.state.speakers || [], this.state.listeners || []);
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
                case 'join': return this.join();
                case 'leave': return this.leave(false);
                case 'start':
                    this.unlockAudio();
                    return this.action('/start');
                case 'end':
                    if (await this.confirm(this.tr('end_confirm'), this.tr('end'))) await this.action('/end');
                    return;
                case 'mic': return this.toggleMic();
                case 'hand': return this.action('/hand', { raised: !(this.state.me && this.state.me.hand_raised) });
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

        /** Close the member menu and return focus to the person it was opened for. */
        closeMenu() {
            if (!this.openMenu) return;
            this.pendingFocus = '[data-action="menu"][data-target="' + cssEsc(this.openMenu) + '"]';
            this.openMenu = null;
            this.render();
        }

        // ─── Confirmation dialog (replaces window.confirm) ─────────────────

        confirm(title, confirmLabel) {
            if (this.dialog) this.dialog.resolve(false);
            return new Promise((resolve) => {
                const active = document.activeElement;
                this.dialog = { title, confirmLabel, resolve, returnTo: active && this.el.contains(active) ? active : null };
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
                '<div class="car-dialog-actions">' +
                    '<button type="button" class="car-btn" data-action="dialog-cancel">' + esc(this.tr('cancel')) + '</button>' +
                    '<button type="button" class="car-btn car-btn--danger-solid" data-action="dialog-confirm">' + esc(this.dialog.confirmLabel) + '</button>' +
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
                    : '<div class="car-body"><p class="car-empty">' + esc(this.tr('state_connecting')) + '</p></div>';
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
            if (room.audience_label) meta.push(esc(room.audience_label));
            if (room.status === 'live') meta.push(esc(this.tr('people_count', { count: s.counts.people })));

            const audio = Array.from(this.el.querySelectorAll('audio'));
            // The same live-region node is kept, so screen readers neither miss nor repeat announcements.
            const live = this.el.querySelector('[data-live]');
            const opening = this.menuJustOpened;
            this.el.innerHTML =
                '<header class="car-header"><div class="car-header-top"><div class="car-header-main">' +
                    '<h2 class="car-title">' + esc(room.title) + '</h2>' +
                    (meta.length ? '<div class="car-meta">' + meta.map((m) => '<span>' + m + '</span>').join('') + '</div>' : '') +
                '</div>' + statusBadge + '</div>' +
                (room.description ? '<p class="car-description">' + esc(room.description) + '</p>' : '') +
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

        /** Shown to a listener a host invited to speak; nothing changes until they accept. */
        renderInvitation() {
            const me = this.state.me;
            if (!this.joined || !me || !me.invited || me.role !== 'listener') return '';
            const id = this.uid + '-invite';
            return '<div class="car-invite" role="group" aria-labelledby="' + id + '">' +
                '<div class="car-invite-text"><p class="car-invite-title" id="' + id + '">' + esc(this.tr('invitation_title')) + '</p>' +
                '<p class="car-invite-body">' + esc(this.tr('invitation_body')) + '</p></div>' +
                '<div class="car-invite-actions">' +
                    '<button type="button" class="car-btn car-btn--small" data-action="invite-decline">' + esc(this.tr('not_now')) + '</button>' +
                    '<button type="button" class="car-btn car-btn--small car-btn--primary" data-action="invite-accept">' + ICONS.mic + esc(this.tr('accept')) + '</button>' +
                '</div></div>';
        }

        banner(kind, text, extra) {
            return '<div class="car-banner car-banner--' + esc(kind) + '" role="' + (kind === 'error' ? 'alert' : 'status') + '"><span>' + esc(text) + '</span>' + (extra || '') + '</div>';
        }

        renderSections() {
            const s = this.state;
            const html = [];

            if (s.can.moderate && (s.requests || []).length) {
                const id = this.uid + '-req';
                html.push('<section class="car-section" id="' + id + '" aria-labelledby="' + id + '-title"><h3 class="car-section-title" id="' + id + '-title">' + esc(this.tr('requests')) + ' <span class="car-count">' + s.requests.length + '</span></h3><div class="car-requests">' +
                    s.requests.map((m) => '<div class="car-request">' + this.avatar(m) + '<span class="car-request-name">' + esc(m.name) + '</span>' +
                        '<button type="button" class="car-btn car-btn--small car-btn--primary" data-action="approve_speaker" data-target="' + esc(m.key) + '">' + esc(this.tr('approve')) + '</button>' +
                        '<button type="button" class="car-btn car-btn--small" data-action="decline_request" data-target="' + esc(m.key) + '">' + esc(this.tr('decline')) + '</button></div>').join('') +
                    '</div></section>');
            }

            html.push(this.section('hosts', s.hosts, false));
            html.push(this.section('speakers', s.speakers, false));
            html.push(this.section('listeners', s.listeners, true));
            return html.join('');
        }

        section(name, people, small) {
            const items = (people || []).map((m) => this.person(m)).join('');
            const empty = name === 'listeners' ? this.tr('empty_listeners') : '';
            if (!items && name === 'speakers') return '';
            const id = this.uid + '-' + name;
            return '<section class="car-section" aria-labelledby="' + id + '"><h3 class="car-section-title" id="' + id + '">' + esc(this.tr(name)) +
                ' <span class="car-count">' + (people || []).length + '</span></h3>' +
                (items ? '<div class="car-grid' + (small ? ' car-grid--listeners' : '') + '">' + items + '</div>' : '<p class="car-empty">' + esc(empty || this.tr('nobody_here')) + '</p>') +
                '</section>';
        }

        avatar(m) {
            return '<span class="car-avatar">' + (m.avatar_url ? '<img src="' + esc(m.avatar_url) + '" alt="" loading="lazy">' : esc(initials(m.name))) + '</span>';
        }

        person(m) {
            const s = this.state;
            const me = s.me;
            const isMe = me && m.key === me.key;
            const speaks = ROLE_SPEAKS[m.role];
            const roleLabel = m.role === 'listener' ? '' : this.tr(m.role);
            const badges = [];
            if (speaks && (m.self_muted || m.moderator_muted)) {
                badges.push('<span class="car-icon-badge' + (m.moderator_muted ? ' car-icon-badge--danger' : '') + '" title="' + esc(this.tr(m.moderator_muted ? 'muted_by_host' : 'muted')) + '">' + ICONS.micOff + '</span>');
            }
            if (m.hand_raised) badges.push('<span class="car-icon-badge car-icon-badge--hand" title="' + esc(this.tr('hand_raised')) + '">' + ICONS.hand + '</span>');
            else if (m.invited) badges.push('<span class="car-icon-badge car-icon-badge--invite" title="' + esc(this.tr('invited_badge')) + '">' + ICONS.mic + '</span>');

            const canAct = s.can.moderate && !isMe && !(m.role === 'host' && me.role !== 'host');
            const states = [m.hand_raised ? this.tr('hand_raised') : (m.invited ? this.tr('invited_badge') : '')].filter(Boolean);
            const label = esc(m.name) + (isMe ? ' (' + esc(this.tr('you')) + ')' : '') + (roleLabel ? ', ' + esc(roleLabel) : '') + (states.length ? ', ' + esc(states.join(', ')) : '');
            // Moderators open the member menu from the card (it has a "View profile" item); others get a plain link.
            const profile = !canAct && !isMe ? safeUrl(m.profile_url) : null;
            const nameText = esc(isMe ? this.tr('you') : m.name);

            let html = '<div class="car-person' + (m.connected === false ? ' is-disconnected' : '') + (m.invited ? ' is-invited' : '') + '" data-person="' + esc(m.key) + '">' +
                '<span class="car-avatar">' + (m.avatar_url ? '<img src="' + esc(m.avatar_url) + '" alt="" loading="lazy">' : esc(initials(m.name))) + badges.join('') + '</span>' +
                (profile
                    ? '<a class="car-person-name" href="' + esc(profile) + '" target="_blank" rel="noopener noreferrer" title="' + esc(this.tr('view_profile')) + ': ' + esc(m.name) + '">' + nameText + '</a>'
                    : '<span class="car-person-name" title="' + esc(m.name) + '">' + nameText + '</span>') +
                (roleLabel ? '<span class="car-person-role">' + esc(roleLabel) + '</span>' : '');

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
            if (ROLE_SPEAKS[m.role] && m.role !== 'host') {
                if (!m.moderator_muted) items.push(btn('mute')); else items.push(btn('allow_unmute'));
                items.push(btn('demote'));
            }
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
                if (window.RTCPeerConnection && (room.status === 'live' || isHost)) {
                    out.push('<button type="button" class="car-btn car-btn--primary" data-action="join"' + (this.status === 'joining' ? ' disabled' : '') + '>' +
                        esc(this.status === 'joining' ? this.tr('joining') : (room.status === 'live' ? this.tr('join') : this.tr('join_as_host'))) + '</button>');
                }
                if (this.config.background_note !== false) out.push('<span class="car-meta car-meta--inline">' + esc(this.tr('background_note')) + '</span>');
                return '<div class="car-controls">' + out.join('') + '</div>';
            }

            if (me && ROLE_SPEAKS[me.role]) {
                const live = !me.self_muted;
                out.push('<button type="button" class="car-btn ' + (live ? 'car-btn--primary' : 'car-btn--mic-off') + '" data-action="mic" aria-pressed="' + live + '"' + (this.micStarting ? ' disabled' : '') + '>' +
                    (live ? ICONS.mic : ICONS.micOff) + '<span>' + esc(this.micStarting ? this.tr('mic_starting') : this.tr(live ? 'mic_on' : 'mic_off')) + '</span></button>');
            } else if (s.can.request_to_speak) {
                out.push('<button type="button" class="car-btn' + (me.hand_raised ? ' car-btn--primary' : '') + '" data-action="hand" aria-pressed="' + Boolean(me.hand_raised) + '">' + ICONS.hand +
                    '<span>' + esc(this.tr(me.hand_raised ? 'lower_hand' : 'raise_hand')) + '</span></button>');
            }
            if (s.can.start) out.push('<button type="button" class="car-btn car-btn--primary" data-action="start">' + esc(this.tr('start')) + '</button>');
            const waiting = (s.requests || []).length;
            if (s.can.moderate && waiting) {
                const nudge = Date.now() - this.requestAlertAt < 1500 ? ' is-attention' : '';
                out.push('<button type="button" class="car-btn' + nudge + '" data-action="show-requests" aria-label="' + esc(this.tr('new_requests', { count: waiting })) + '">' + ICONS.hand +
                    '<span class="car-btn-label--hide-sm">' + esc(this.tr('requests')) + '</span><span class="car-btn-count">' + waiting + '</span></button>');
            }

            out.push('<span class="car-controls-spacer"></span>');
            if (s.can.end) out.push('<button type="button" class="car-btn car-btn--danger car-btn--small" data-action="end">' + esc(this.tr('end')) + '</button>');
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
                });
                window.location.href = data.url;
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

        const item = (room) => {
            const meta = [];
            if (room.audience_label) meta.push(esc(room.audience_label));
            if (room.status === 'live') meta.push(esc(String(t.people_count || ':count').split(':count').join(room.people)));
            if (room.is_mine) meta.push(esc(t.yours));
            const href = safeUrl(room.url);
            return '<' + (href ? 'a href="' + esc(href) + '"' : 'div') + ' class="car-list-item"><div class="car-list-item-main">' +
                '<div class="car-list-item-title">' + esc(room.title) + '</div>' +
                '<div class="car-meta">' + meta.map((m) => '<span>' + m + '</span>').join('') + '</div></div>' +
                (room.status === 'live' ? '<span class="car-badge car-badge--live">' + esc(t.live) + '</span>' : '<span class="car-badge">' + esc(t.scheduled) + '</span>') +
                '</' + (href ? 'a' : 'div') + '>';
        };

        const refresh = async () => {
            if (document.hidden) return;
            try {
                const data = await api.request('GET', '');
                const html = (data.rooms || []).length
                    ? '<div class="car-list">' + data.rooms.map(item).join('') + '</div>'
                    : '<p class="car-empty">' + esc(t.no_rooms) + '</p>';
                // Skip identical updates so focus and hover are not disturbed.
                if (html !== last) { last = html; el.innerHTML = html; }
            } catch (e) { /* keep the current list */ }
        };

        setInterval(refresh, 15000);
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
        document.querySelectorAll('[data-audio-rooms-list]:not([data-car-mounted])').forEach((list) => {
            list.setAttribute('data-car-mounted', '');
            mountRoomList(list);
        });
    }

    window.CreatorsAudioRooms = { mount: mountAll, AudioRoom };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll);
    else mountAll();
})();
