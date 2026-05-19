/**
 * <ai-bridge-chat> — reference chat UI for the AI Bridge conversation API.
 *
 * A self-contained Web Component. All markup + styles live inside a Shadow
 * DOM, so it CANNOT conflict with the host application's CSS framework or
 * JavaScript (no global Tailwind, no global Alpine — nothing leaks in or out).
 *
 * It is a thin client of the package's public HTTP API; all real logic is
 * server-side. To customise the UI, either build your own UI against that
 * same HTTP API (recommended) or fork this file. See the package README.
 *
 * Attributes:
 *   api               Base path of the AI Bridge API (default "/ai-bridge").
 *   thinking-visible  "false" to hide expandable thinking blocks (default true).
 *   reverb-key/-host/-port/-scheme  Reverb connection details (bridge mode).
 *   assets            Base path the package serves vendored pusher/echo from.
 */
(function () {
    'use strict';

    const CSS = `
    :host { all: initial; }
    * { box-sizing: border-box; }
    .root {
        display: flex; height: 80vh; min-height: 520px; overflow: hidden;
        border: 1px solid #e5e7eb; border-radius: 12px; background: #fff;
        font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
        color: #111827; font-size: 14px;
    }
    .sidebar { width: 280px; display: flex; flex-direction: column;
        border-right: 1px solid #e5e7eb; background: #f9fafb; }
    .sidebar-head { display: flex; align-items: center; justify-content: space-between; padding: 12px; }
    .sidebar-head b { font-size: 13px; }
    .conv-list { flex: 1; overflow-y: auto; padding: 0 8px 8px; }
    .conv { display: flex; justify-content: space-between; align-items: center;
        padding: 8px 10px; border-radius: 8px; cursor: pointer; margin-bottom: 4px; }
    .conv:hover { background: #f3f4f6; }
    .conv.active { background: #e5e7eb; }
    .conv .title { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .conv .sub { font-size: 11px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .conv .del { color: #9ca3af; visibility: hidden; background: none; border: 0; cursor: pointer; }
    .conv:hover .del { visibility: visible; }
    .empty { padding: 16px 8px; font-size: 12px; color: #9ca3af; }
    .setup { border-top: 1px solid #e5e7eb; padding: 12px; }
    .setup > button { width: 100%; display: flex; justify-content: space-between;
        background: none; border: 0; cursor: pointer; font-size: 12px; color: #4b5563; font-weight: 500; }
    .conn { border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; padding: 8px;
        font-size: 11px; margin-top: 8px; cursor: pointer; }
    .conn:hover { border-color: #9ca3af; background: #f9fafb; }
    .conn b { display: block; }
    .conn span { color: #6b7280; }
    .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%;
        margin-right: 5px; vertical-align: middle; }
    .dot.on { background: #10b981; }
    .dot.off { background: #d1d5db; }
    button.ghost.danger { color: #b91c1c; }
    .main { flex: 1; display: flex; flex-direction: column; }
    .placeholder { flex: 1; display: flex; align-items: center; justify-content: center; color: #9ca3af; }
    .header { display: flex; align-items: center; justify-content: space-between;
        border-bottom: 1px solid #e5e7eb; padding: 10px 16px; }
    .header .name { font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 2px;
        min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .badge { background: #f3f4f6; color: #4b5563; border-radius: 999px; padding: 2px 8px; font-size: 11px; margin-left: 6px; }
    .stale { background: #fffbeb; color: #92400e; border-bottom: 1px solid #fde68a; padding: 6px 16px; font-size: 11px; }
    .messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
    .msg { display: flex; }
    .msg.user { justify-content: flex-end; }
    .msg .stack { max-width: 80%; display: flex; flex-direction: column; gap: 8px; }
    .bubble { padding: 8px 14px; border-radius: 16px; white-space: pre-wrap; word-break: break-word; }
    .msg.user .bubble { background: #111827; color: #fff; }
    .msg.assistant .bubble { background: #f3f4f6; color: #111827; }
    .think { border: 1px solid #ddd6fe; background: #f5f3ff; border-radius: 12px; padding: 6px 12px; font-size: 12px; color: #6d28d9; }
    .think button { background: none; border: 0; cursor: pointer; color: #6d28d9; font-weight: 500; padding: 0; }
    .think .body { margin-top: 4px; color: #7c3aed; white-space: pre-wrap; }
    .tool { border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 12px; padding: 8px 12px; font-size: 12px; }
    .tool b { color: #1d4ed8; }
    .tool pre { margin: 4px 0 0; overflow-x: auto; color: #2563eb; font-size: 11px; }
    .tool .res { margin-top: 4px; border-top: 1px solid #bfdbfe; padding-top: 4px; color: #2563eb; }
    .pulse { display: flex; align-items: center; gap: 4px; background: #f3f4f6; color: #6b7280;
        border-radius: 16px; padding: 8px 14px; width: fit-content; }
    .pulse i { width: 6px; height: 6px; border-radius: 50%; background: #9ca3af; display: inline-block;
        animation: ab-blink 1.2s infinite; }
    .pulse i:nth-child(3) { animation-delay: .2s; } .pulse i:nth-child(4) { animation-delay: .4s; }
    @keyframes ab-blink { 0%,100%{opacity:.3} 50%{opacity:1} }
    .err { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 8px 12px; font-size: 12px; }
    .composer { border-top: 1px solid #e5e7eb; padding: 12px; display: flex; gap: 8px; }
    input, select, textarea { font: inherit; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 10px; color: #111827; }
    input:focus, select, textarea:focus { outline: none; }
    .composer input { flex: 1; }
    button.primary { background: #111827; color: #fff; border: 0; border-radius: 8px;
        padding: 8px 16px; cursor: pointer; font-weight: 500; }
    button.primary:disabled { opacity: .4; cursor: not-allowed; }
    button.small { background: #111827; color: #fff; border: 0; border-radius: 6px;
        padding: 4px 10px; font-size: 12px; cursor: pointer; }
    button.ghost { background: none; border: 0; color: #4b5563; cursor: pointer; }
    button.dashed { width: 100%; border: 1px dashed #d1d5db; background: none; border-radius: 8px;
        padding: 6px; font-size: 11px; color: #4b5563; cursor: pointer; margin-top: 8px; }
    .overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex;
        align-items: center; justify-content: center; padding: 16px; z-index: 50;
        font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
        color: #111827; font-size: 14px; }
    .modal { background: #fff; border-radius: 12px; padding: 20px; width: 100%; max-width: 420px; }
    .modal h3 { margin: 0 0 12px; font-size: 16px; }
    .modal label { display: block; font-size: 12px; font-weight: 500; color: #4b5563; margin-bottom: 4px; }
    .modal select, .modal input, .modal textarea { width: 100%; margin-bottom: 12px; }
    .modal .actions { display: flex; justify-content: flex-end; gap: 8px; }
    .hint { font-size: 12px; color: #4b5563; margin: 0 0 10px; }
    .cmd { background: #111827; color: #f3f4f6; border-radius: 8px; padding: 12px;
        font-size: 13px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap;
        word-break: break-all; cursor: pointer; user-select: all;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .cmd:hover { background: #1f2937; }
    .cmd-hint { font-size: 11px; color: #6b7280; margin-top: 6px; }
    .cmd-hint.ok { color: #047857; font-weight: 500; }
    .hidden { display: none !important; }

    /* Hamburger toggle for the sidebar drawer — hidden on desktop, where the
       sidebar is always visible; revealed by the mobile media query below. */
    .burger { display: none; align-items: center; justify-content: center;
        background: none; border: 0; cursor: pointer; font-size: 18px; line-height: 1;
        color: #4b5563; padding: 4px 8px; margin-right: 2px; }
    .burger:hover { color: #111827; }
    /* Backdrop behind the open drawer — only ever visible on mobile. */
    .sidebar-backdrop { display: none; }

    /* ── Mobile / narrow screens ──────────────────────────────────────
       The 280px sidebar would eat most of a phone screen, so below 640px it
       becomes an off-canvas drawer: hidden by default, slid in over the chat
       by the .burger toggle (see togglesidebar), dismissed by the backdrop. */
    @media (max-width: 640px) {
        .root { position: relative; min-height: 0; height: 82vh; }
        .sidebar {
            position: absolute; top: 0; left: 0; bottom: 0;
            width: 84%; max-width: 320px; z-index: 40;
            transform: translateX(-100%); transition: transform .22s ease;
            box-shadow: 2px 0 18px rgba(0,0,0,.18);
        }
        .root.sidebar-open .sidebar { transform: translateX(0); }
        .sidebar-backdrop {
            display: block; position: absolute; inset: 0; z-index: 30;
            background: rgba(0,0,0,.4); opacity: 0; pointer-events: none;
            transition: opacity .22s ease;
        }
        .root.sidebar-open .sidebar-backdrop { opacity: 1; pointer-events: auto; }
        .burger { display: inline-flex; }
        .header { padding: 10px 12px; }
        .messages { padding: 12px; }
        .msg .stack { max-width: 92%; }
        .composer { padding: 8px; }
        .composer input { min-width: 0; }
    }
    `;

    class AiBridgeChat extends HTMLElement {
        connectedCallback() {
            if (this._booted) return;
            this._booted = true;

            this.api = this.getAttribute('api') || '/ai-bridge';
            this.assets = this.getAttribute('assets') || (this.api + '/assets');
            this.thinkingVisible = this.getAttribute('thinking-visible') !== 'false';
            this.reverb = {
                key: this.getAttribute('reverb-key') || null,
                host: this.getAttribute('reverb-host') || location.hostname,
                port: this.getAttribute('reverb-port') || '8080',
                scheme: this.getAttribute('reverb-scheme') || 'http',
            };

            this.s = {
                conversations: [], connections: [], activeId: null, conv: null,
                messages: [], toolsStale: false, setupOpen: false,
                modal: null, bridgeCommand: null, cmdCopied: false,
                streaming: false, error: null, pulse: false, form: {},
                // Mobile only: whether the off-canvas sidebar drawer is open.
                sidebarOpen: false,
            };

            // Watchdog budgets (see armWatchdog). The first event after a send
            // must absorb a cold CLI start + a possible transparent
            // session_lost recovery, so it is given a wider window; once
            // events are flowing, a long silence is a fair stall signal.
            this.WATCHDOG_FIRST_MS = 120000;
            this.WATCHDOG_STEADY_MS = 45000;

            this.root = this.attachShadow({ mode: 'open' });
            const style = document.createElement('style');
            style.textContent = CSS;
            this.root.appendChild(style);
            this.container = document.createElement('div');
            this.root.appendChild(this.container);

            this.root.addEventListener('click', (e) => this.onClick(e));
            this.renderAll();
            this.init();
        }

        async init() {
            await this.loadConnections();
            await this.loadConversations();
            this.renderAll();
            // Live connection updates are pushed over Reverb (see
            // subscribeConnections). Two cheap safety nets cover a status
            // event that never arrives — Reverb not configured, the tab
            // asleep, or a dropped broadcast: a refresh on tab re-focus, and
            // a slow (60s) fallback poll. Both are no-ops when nothing
            // meaningful changed (see refreshConnections / _connSnapshot).
            this.connSnapshot = this._connSnapshot(this.s.connections);
            this.subscribeConnections();
            this._onVisible = () => {
                if (document.visibilityState === 'visible') this.refreshConnections();
            };
            document.addEventListener('visibilitychange', this._onVisible);
            this._fallbackTimer = setInterval(() => this.refreshConnections(), 60000);
        }

        disconnectedCallback() {
            if (this._onVisible) {
                document.removeEventListener('visibilitychange', this._onVisible);
                this._onVisible = null;
            }
            if (this._fallbackTimer) {
                clearInterval(this._fallbackTimer);
                this._fallbackTimer = null;
            }
            if (this.echo && this._connChannels) {
                Object.keys(this._connChannels).forEach((ch) => {
                    try { this.echo.leave(ch); } catch (e) {}
                });
            }
            this._connChannels = {};
            this.clearWatchdog();
            if (this.echo && this._streamChannel) {
                try { this.echo.leave(this._streamChannel); } catch (e) {}
                this._streamChannel = null;
            }
        }

        // ── live connection updates (push, with a slow poll fallback) ──
        // A CLI bridge is started manually by the user AFTER this UI loads, so
        // its providers/models are unknown on the first fetch. The bridge
        // server broadcasts a "connection.status" event when one connects or
        // drops; subscribeConnections() listens for it and refreshes. The 60s
        // fallback poll in init() only covers a missed/undeliverable event.
        async subscribeConnections() {
            let echo;
            try { echo = await this.getEcho(); } catch (e) { echo = null; }
            if (!echo) return; // no Reverb configured — list updates on refocus
            this._connChannels = this._connChannels || {};
            const wanted = {};
            this.s.connections.forEach((c) => { if (c.channel) wanted[c.channel] = true; });
            // Drop channels for connections that no longer exist.
            Object.keys(this._connChannels).forEach((ch) => {
                if (!wanted[ch]) { try { echo.leave(ch); } catch (e) {} delete this._connChannels[ch]; }
            });
            // Subscribe to channels we are not yet listening on.
            Object.keys(wanted).forEach((ch) => {
                if (this._connChannels[ch]) return;
                this._connChannels[ch] = true;
                echo.private(ch).listen('.connection.status', () => this.refreshConnections());
            });
        }

        // Snapshot of only the *meaningful* connection fields. The /connections
        // payload also carries last_connected_at, which the server refreshes on
        // every call — including it would make every snapshot differ and force
        // a needless re-render. Compare on identity + capabilities only.
        _connSnapshot(conns) {
            return JSON.stringify((conns || []).map((c) => ({
                id: c.id, type: c.type, name: c.name, providers: c.providers,
                connected: c.connected,
            })));
        }

        // Re-fetch connections, re-rendering only if something meaningful
        // changed. Triggered by a status push or by a tab re-focus.
        async refreshConnections() {
            let data;
            try {
                data = await this.apiCall('/connections');
            } catch (e) {
                return; // keep existing data on a transient failure
            }
            const conns = data.connections || [];
            const snapshot = this._connSnapshot(conns);
            if (snapshot !== this.connSnapshot) {
                this.connSnapshot = snapshot;
                this.s.connections = conns;
                this.renderAll();
            }
            this.subscribeConnections(); // pick up channels for new connections
        }

        // ── HTTP ──────────────────────────────────────────────────────
        async apiCall(path, opts = {}) {
            const res = await fetch(this.api + path, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                ...opts,
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.status === 204 ? null : res.json();
        }
        async loadConversations() {
            try {
                const d = await this.apiCall('/conversations');
                this.s.conversations = d.data || d || [];
            } catch (e) { this.s.conversations = []; }
        }
        async loadConnections() {
            try {
                const d = await this.apiCall('/connections');
                this.s.connections = d.connections || [];
            } catch (e) { this.s.connections = []; }
        }

        // ── rendering ─────────────────────────────────────────────────
        esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

        renderAll() {
            const live = this._captureInputs();
            const rootClass = 'root' + (this.s.sidebarOpen ? ' sidebar-open' : '');
            // .sidebar-backdrop sits under the drawer; it is display:none on
            // desktop and only catches taps (to close the drawer) on mobile.
            this.container.innerHTML =
                `<div class="${rootClass}">${this.sidebarHtml()}${this.mainHtml()}` +
                `<div class="sidebar-backdrop" data-act="togglesidebar"></div></div>` +
                `${this.modalHtml()}`;
            this._restoreInputs(live);
            this.scrollDown();
        }

        // Capture in-progress text input before a re-render so an unrelated
        // re-render (notably the periodic connection poll) never wipes what the
        // user is typing, nor steals focus / caret position from them.
        _captureInputs() {
            const values = {};
            const active = this.root.activeElement;
            let focusKey = null, selStart = null, selEnd = null;
            this.root.querySelectorAll('input, textarea').forEach((el) => {
                const key = el.dataset.field || el.name;
                if (!key) return;
                values[key] = el.value;
                if (el === active) {
                    focusKey = key;
                    try { selStart = el.selectionStart; selEnd = el.selectionEnd; } catch (e) {}
                }
            });
            return { values, focusKey, selStart, selEnd };
        }

        // Restore values + focus + caret captured by _captureInputs(). Fields
        // that no longer exist (e.g. the modal closed) are simply skipped.
        _restoreInputs(live) {
            if (!live) return;
            this.root.querySelectorAll('input, textarea').forEach((el) => {
                const key = el.dataset.field || el.name;
                if (key && Object.prototype.hasOwnProperty.call(live.values, key)) {
                    el.value = live.values[key];
                }
            });
            if (live.focusKey) {
                const el = this.root.querySelector(
                    '[data-field="' + live.focusKey + '"], [name="' + live.focusKey + '"]');
                if (el) {
                    el.focus();
                    if (live.selStart != null) {
                        try { el.setSelectionRange(live.selStart, live.selEnd); } catch (e) {}
                    }
                }
            }
        }

        sidebarHtml() {
            const convs = this.s.conversations.map((c) => `
                <div class="conv ${c.id === this.s.activeId ? 'active' : ''}" data-act="open" data-id="${c.id}">
                    <div style="min-width:0">
                        <div class="title">${this.esc(c.title || 'Conversation #' + c.id)}</div>
                        <div class="sub">${this.esc((c.provider || c.mode) + (c.model ? ' · ' + c.model : ''))}</div>
                    </div>
                    <button class="del" data-act="del" data-id="${c.id}">✕</button>
                </div>`).join('');
            const conns = this.s.connections.map((cn) => {
                const provs = (cn.providers || []).map((p) => p.name).join(', ') || 'no providers';
                // Only CLI bridges have a process whose presence can change —
                // BYOK endpoints are always reachable, so they get no status dot.
                const dot = cn.type === 'bridge'
                    ? `<i class="dot ${cn.connected ? 'on' : 'off'}" title="${cn.connected ? 'Connected' : 'Not connected'}"></i>`
                    : '';
                return `<div class="conn" data-act="manageconn" data-id="${cn.id}" title="Manage this connection">` +
                    `<b>${dot}${this.esc(cn.name || cn.type)}</b><span>${this.esc(provs)}</span></div>`;
            }).join('');
            return `
            <div class="sidebar">
                <div class="sidebar-head"><b>Conversations</b>
                    <button class="small" data-act="newchat">+ New</button></div>
                <div class="conv-list">${convs || '<p class="empty">No conversations yet.</p>'}</div>
                <div class="setup">
                    <button data-act="togglesetup"><span>Setup &amp; connections</span><span>${this.s.setupOpen ? '▾' : '▸'}</span></button>
                    <div class="${this.s.setupOpen ? '' : 'hidden'}">
                        ${conns}
                        <button class="dashed" data-act="addbridge">+ Add a CLI bridge</button>
                        <button class="dashed" data-act="addbyok">+ Add a BYOK endpoint</button>
                    </div>
                </div>
            </div>`;
        }

        mainHtml() {
            // Hamburger that opens the sidebar drawer. Rendered in every state
            // (incl. the empty placeholder) so a mobile user can always reach
            // the conversation list; CSS hides it on desktop.
            const burger = `<button class="burger" data-act="togglesidebar" aria-label="Show conversations" title="Conversations">☰</button>`;
            if (!this.s.conv) {
                return `<div class="main">
                    <div class="header"><div class="name">${burger}Conversations</div><div></div></div>
                    <div class="placeholder">Select or start a conversation.</div>
                </div>`;
            }
            const c = this.s.conv;
            const msgs = this.s.messages.map((m, mi) => this.msgHtml(m, mi)).join('');
            return `
            <div class="main">
                <div class="header">
                    <div class="name">${burger}${this.esc(c.title || 'Conversation #' + c.id)}</div>
                    <div><span class="badge">${this.esc(c.provider || c.mode)}</span>${c.model ? `<span class="badge">${this.esc(c.model)}</span>` : ''}</div>
                </div>
                ${this.s.toolsStale ? '<div class="stale">This conversation runs on an older tool set — start a new conversation for the freshest tools.</div>' : ''}
                <div class="messages">
                    ${msgs}
                    ${this.s.pulse ? '<div class="pulse"><span>Thinking</span><i></i><i></i><i></i></div>' : ''}
                    ${this.s.error ? `<div class="err">${this.esc(this.s.error)}</div>` : ''}
                </div>
                <form class="composer" data-act="sendform">
                    <input name="draft" placeholder="Type a message…" ${this.s.streaming ? 'disabled' : ''} autocomplete="off">
                    <button class="primary" type="submit" ${this.s.streaming ? 'disabled' : ''}>Send</button>
                </form>
            </div>`;
        }

        msgHtml(m, mi) {
            const blocks = (m.blocks || []).map((b, bi) => {
                if (b.type === 'thinking') {
                    if (!this.thinkingVisible) return '';
                    return `<div class="think">
                        <button data-act="togglethink" data-mi="${mi}" data-bi="${bi}">${b._open ? '▾' : '▸'} Thinking</button>
                        <div class="body ${b._open ? '' : 'hidden'}">${this.esc(b.text)}</div></div>`;
                }
                if (b.type === 'tool_call') {
                    return `<div class="tool"><b>🔧 ${this.esc(b.tool_name)}</b>
                        <pre>${this.esc(JSON.stringify(b.parameters || {}, null, 2))}</pre>
                        ${b.result !== undefined ? `<div class="res">→ ${this.esc(typeof b.result === 'string' ? b.result : JSON.stringify(b.result))}</div>` : ''}</div>`;
                }
                return `<div class="bubble">${this.esc(b.text)}</div>`;
            }).join('');
            return `<div class="msg ${m.role}"><div class="stack">${blocks}</div></div>`;
        }

        modalHtml() {
            if (!this.s.modal) return '';
            let body = '';
            if (this.s.modal === 'chat') {
                const conn = this.selectedConnection();
                const provs = (conn ? conn.providers || [] : []);
                const models = ((provs.find((p) => p.name === this.s.form.provider) || {}).models) || [];
                body = `<h3>New conversation</h3>
                    <label>Connection</label>
                    <select data-field="connectionId"><option value="">— select —</option>
                        ${this.s.connections.map((c) => `<option value="${c.id}" ${String(c.id) === String(this.s.form.connectionId) ? 'selected' : ''}>${this.esc((c.name || c.type) + ' (' + c.type + ')')}</option>`).join('')}</select>
                    <label>Provider</label>
                    <select data-field="provider"><option value="">— select —</option>
                        ${provs.map((p) => `<option value="${this.esc(p.name)}" ${p.name === this.s.form.provider ? 'selected' : ''}>${this.esc(p.name)}</option>`).join('')}</select>
                    <label>Model</label>
                    <select data-field="model"><option value="">— default —</option>
                        ${models.map((m) => `<option value="${this.esc(m.id)}" ${m.id === this.s.form.model ? 'selected' : ''}>${this.esc(m.name || m.id)}</option>`).join('')}</select>
                    <label>System prompt (optional)</label>
                    <textarea data-field="systemPrompt" rows="3">${this.esc(this.s.form.systemPrompt || '')}</textarea>
                    <div class="actions"><button class="ghost" data-act="closemodal">Cancel</button>
                        <button class="primary" data-act="createchat" ${this.s.form.connectionId && this.s.form.provider ? '' : 'disabled'}>Create</button></div>`;
            } else if (this.s.modal === 'bridge') {
                body = this.s.bridgeCommand
                    ? `<h3>Add a CLI bridge</h3>
                       <p class="hint">Run this where your Codex / Claude / Gemini CLI is installed:</p>
                       <div class="cmd" data-act="copycmd" title="Click to copy">${this.esc(this.s.bridgeCommand)}</div>
                       <div class="cmd-hint ${this.s.cmdCopied ? 'ok' : ''}">${this.s.cmdCopied ? '✓ Copied to clipboard' : 'Click the command to copy it'}</div>
                       <div class="actions" style="margin-top:12px"><button class="primary" data-act="closemodal">Done</button></div>`
                    : `<h3>Add a CLI bridge</h3><label>Name (optional)</label><input data-field="name" value="${this.esc(this.s.form.name || '')}">
                       <div class="actions"><button class="ghost" data-act="closemodal">Cancel</button>
                       <button class="primary" data-act="createbridge">Create</button></div>`;
            } else if (this.s.modal === 'byok') {
                body = `<h3>Add a BYOK endpoint</h3>
                    <label>Name (optional)</label><input data-field="name" value="${this.esc(this.s.form.name || '')}">
                    <label>Endpoint</label><input data-field="endpoint" placeholder="https://api.openai.com" value="${this.esc(this.s.form.endpoint || '')}">
                    <label>API key</label><input data-field="apiKey" type="password" placeholder="sk-…" value="${this.esc(this.s.form.apiKey || '')}">
                    <div class="actions"><button class="ghost" data-act="closemodal">Cancel</button>
                        <button class="primary" data-act="createbyok" ${this.s.form.endpoint && this.s.form.apiKey ? '' : 'disabled'}>Save</button></div>`;
            } else if (this.s.modal === 'manage') {
                body = this.manageModalHtml();
            }
            return `<div class="overlay" data-act="overlay"><div class="modal">${body}</div></div>`;
        }

        // Body of the "manage connection" modal — view status, rename,
        // regenerate the token, or delete. Reached by clicking a connection
        // card. After a regenerate it shows the fresh command (reusing the
        // bridgeCommand state, exactly like the add-bridge flow).
        manageModalHtml() {
            const cn = this.s.connections.find((c) => String(c.id) === String(this.s.manageId));
            if (!cn) {
                return `<h3>Connection</h3>
                    <p class="hint">This connection no longer exists.</p>
                    <div class="actions"><button class="primary" data-act="closemodal">Close</button></div>`;
            }
            if (this.s.bridgeCommand) {
                return `<h3>New bridge command</h3>
                    <p class="hint">The previous token has been revoked. Run this where your
                       Codex / Claude / Gemini CLI is installed:</p>
                    <div class="cmd" data-act="copycmd" title="Click to copy">${this.esc(this.s.bridgeCommand)}</div>
                    <div class="cmd-hint ${this.s.cmdCopied ? 'ok' : ''}">${this.s.cmdCopied ? '✓ Copied to clipboard' : 'Click the command to copy it'}</div>
                    <div class="actions" style="margin-top:12px"><button class="primary" data-act="closemodal">Done</button></div>`;
            }
            const isBridge = cn.type === 'bridge';
            const provs = (cn.providers || []).map((p) => p.name).join(', ') || 'none';
            const status = isBridge
                ? `<p class="hint"><i class="dot ${cn.connected ? 'on' : 'off'}"></i>` +
                  `${cn.connected ? 'Connected' : 'Not connected'} · Providers: ${this.esc(provs)}</p>`
                : '';
            return `<h3>Manage ${isBridge ? 'CLI bridge' : 'BYOK endpoint'}</h3>
                ${status}
                <label>Name</label>
                <input data-field="name" value="${this.esc(this.s.form.name || '')}" placeholder="${this.esc(cn.type)}">
                <div class="actions" style="justify-content:space-between;margin-bottom:8px">
                    <button class="ghost danger" data-act="deleteconn">Delete</button>
                    ${isBridge ? '<button class="ghost" data-act="regenconn">Regenerate token</button>' : ''}
                </div>
                <div class="actions">
                    <button class="ghost" data-act="closemodal">Cancel</button>
                    <button class="primary" data-act="saveconn">Save</button>
                </div>`;
        }

        // ── events ────────────────────────────────────────────────────
        onClick(e) {
            const t = e.target.closest('[data-act]');
            if (!t) return;
            const act = t.dataset.act;
            const id = t.dataset.id;
            if (act === 'open') this.openConversation(Number(id));
            else if (act === 'togglesidebar') { this.s.sidebarOpen = !this.s.sidebarOpen; this.renderAll(); }
            else if (act === 'del') { e.stopPropagation(); this.deleteConversation(Number(id)); }
            else if (act === 'newchat') this.openModal('chat');
            else if (act === 'togglesetup') { this.s.setupOpen = !this.s.setupOpen; this.renderAll(); }
            else if (act === 'addbridge') { this.s.form = {}; this.s.bridgeCommand = null; this.s.cmdCopied = false; this.openModal('bridge'); }
            else if (act === 'addbyok') { this.s.form = {}; this.openModal('byok'); }
            else if (act === 'closemodal' || act === 'overlay') { if (act === 'overlay' && e.target !== t) return; this.s.modal = null; this.renderAll(); }
            else if (act === 'copycmd') this.copyCommand();
            else if (act === 'createchat') this.createConversation();
            else if (act === 'createbridge') this.createBridge();
            else if (act === 'createbyok') this.createByok();
            else if (act === 'manageconn') this.openManage(Number(id));
            else if (act === 'saveconn') this.saveConn();
            else if (act === 'regenconn') this.regenConn();
            else if (act === 'deleteconn') this.deleteConn();
            else if (act === 'togglethink') {
                const b = this.s.messages[t.dataset.mi].blocks[t.dataset.bi];
                b._open = !b._open; this.renderAll();
            }
        }

        bindFormInputs() {
            this.root.querySelectorAll('[data-field]').forEach((el) => {
                el.addEventListener('change', () => {
                    this.s.form[el.dataset.field] = el.value;
                    if (el.dataset.field === 'connectionId') { this.s.form.provider = ''; this.s.form.model = ''; this.renderAll(); }
                    else if (el.dataset.field === 'provider') { this.s.form.model = ''; this.renderAll(); }
                    else this.refreshModalButtons();
                });
            });
            // Enter inside a modal text field submits the modal's primary
            // action — modals are not <form>s, so the browser won't do it.
            this.root.querySelectorAll('.modal input').forEach((el) => {
                el.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    this.submitModal();
                });
            });
            const form = this.root.querySelector('[data-act="sendform"]');
            if (form) form.addEventListener('submit', (e) => { e.preventDefault(); this.send(form.draft.value); });
        }
        // Update the modal's primary-button enabled state IN PLACE. A full
        // renderAll() here would rebuild the DOM in the window between a
        // button's mousedown and mouseup — destroying the button mid-click so
        // the click never lands, which is why "Create" used to need two
        // clicks. Toggling .disabled leaves the element (and the click) intact.
        refreshModalButtons() {
            const f = this.s.form;
            const toggle = (act, enabled) => {
                const b = this.root.querySelector('.modal button[data-act="' + act + '"]');
                if (b) b.disabled = !enabled;
            };
            if (this.s.modal === 'chat') toggle('createchat', !!(f.connectionId && f.provider));
            else if (this.s.modal === 'byok') toggle('createbyok', !!(f.endpoint && f.apiKey));
            // The 'bridge' and 'manage' modal primary buttons are never disabled.
        }

        // Commit any not-yet-blurred field values, then trigger the modal's
        // primary action. Backs Enter-to-submit, since a `keydown` does not
        // fire the `change` event that normally syncs fields into s.form.
        submitModal() {
            this.root.querySelectorAll('.modal [data-field]').forEach((el) => {
                this.s.form[el.dataset.field] = el.value;
            });
            this.renderAll();
            const btn = this.root.querySelector('.modal button.primary');
            if (btn && !btn.disabled) btn.click();
        }

        openModal(kind) { this.s.modal = kind; this.renderAll(); }
        selectedConnection() {
            return this.s.connections.find((c) => String(c.id) === String(this.s.form.connectionId)) || null;
        }

        // ── conversations ─────────────────────────────────────────────
        async openConversation(id) {
            this.s.activeId = id; this.s.error = null;
            this.s.sidebarOpen = false; // close the mobile drawer once a chat is picked
            let channel = null;
            try {
                const d = await this.apiCall('/conversations/' + id);
                this.s.conv = d.conversation;
                this.s.toolsStale = !!d.tools_stale;
                channel = d.channel || null;
                this.s.messages = (d.messages || []).map((m) => ({
                    role: m.role,
                    blocks: (Array.isArray(m.blocks) && m.blocks.length)
                        ? m.blocks.map((b) => ({ ...b, _open: false }))
                        : [{ type: 'text', text: m.content || '' }],
                }));
            } catch (e) { this.s.error = e.message; }
            this.renderAll();
            // Subscribe to the conversation's stream channel NOW, on open —
            // not when a message is sent. A fast terminal event (e.g. an
            // immediate error) would otherwise be broadcast before the browser
            // finished subscribing, and Reverb does not replay missed events.
            if (channel) this.subscribeConversation(channel);
        }
        async deleteConversation(id) {
            await this.apiCall('/conversations/' + id, { method: 'DELETE' });
            if (this.s.activeId === id) { this.s.activeId = null; this.s.conv = null; this.s.messages = []; }
            await this.loadConversations();
            this.renderAll();
        }
        async createConversation() {
            const conn = this.selectedConnection();
            try {
                const conv = await this.apiCall('/conversations', {
                    method: 'POST',
                    body: JSON.stringify({
                        mode: conn && conn.type === 'byok' ? 'byok' : 'bridge',
                        connection_id: this.s.form.connectionId,
                        provider: this.s.form.provider,
                        model: this.s.form.model || null,
                        system_prompt: this.s.form.systemPrompt || null,
                    }),
                });
                this.s.modal = null;
                await this.loadConversations();
                await this.openConversation(conv.id);
            } catch (e) { this.s.error = e.message; this.renderAll(); }
        }
        async createBridge() {
            try {
                const d = await this.apiCall('/connections', {
                    method: 'POST', body: JSON.stringify({ type: 'bridge', name: this.s.form.name || null }),
                });
                this.s.bridgeCommand = d.command;
                this.s.cmdCopied = false;
                await this.loadConnections();
                this.subscribeConnections(); // listen for the new bridge coming online
            } catch (e) { this.s.error = e.message; this.s.modal = null; }
            this.renderAll();
        }

        // ── manage an existing connection (status / rename / regen / delete) ─
        openManage(id) {
            const cn = this.s.connections.find((c) => String(c.id) === String(id));
            this.s.manageId = id;
            this.s.bridgeCommand = null;
            this.s.cmdCopied = false;
            this.s.form = { name: (cn && cn.name) || '' };
            this.openModal('manage');
        }

        async saveConn() {
            try {
                await this.apiCall('/connections/' + this.s.manageId, {
                    method: 'PATCH', body: JSON.stringify({ name: this.s.form.name || null }),
                });
                this.s.modal = null;
                await this.loadConnections();
            } catch (e) { this.s.error = e.message; }
            this.renderAll();
        }

        async deleteConn() {
            const cn = this.s.connections.find((c) => String(c.id) === String(this.s.manageId));
            const label = (cn && (cn.name || cn.type)) || 'this connection';
            if (!confirm('Delete "' + label + '"? Any bridge currently connected with it '
                + 'will be disconnected. This cannot be undone.')) return;
            try {
                await this.apiCall('/connections/' + this.s.manageId, { method: 'DELETE' });
                this.s.modal = null;
                await this.loadConnections();
                this.subscribeConnections(); // drop the deleted bridge's status channel
            } catch (e) { this.s.error = e.message; }
            this.renderAll();
        }

        async regenConn() {
            if (!confirm('Regenerate this bridge\'s token? The current token stops working '
                + 'immediately — any bridge using it is disconnected and must be restarted '
                + 'with the new command.')) return;
            try {
                const d = await this.apiCall('/connections/' + this.s.manageId + '/regenerate', { method: 'POST' });
                this.s.bridgeCommand = d.command;
                this.s.cmdCopied = false;
                await this.loadConnections();
            } catch (e) { this.s.error = e.message; this.s.modal = null; }
            this.renderAll();
        }

        // Copy the bridge command to the clipboard. Uses the async Clipboard
        // API where available, with an execCommand fallback for non-HTTPS
        // origins; brief "Copied" feedback is driven by the cmdCopied flag.
        async copyCommand() {
            const text = this.s.bridgeCommand || '';
            let ok = false;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                    ok = true;
                }
            } catch (e) { ok = false; }
            if (!ok) {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                ta.remove();
            }
            this.s.cmdCopied = ok;
            this.renderAll();
            if (ok) {
                clearTimeout(this._cmdCopiedTimer);
                this._cmdCopiedTimer = setTimeout(() => {
                    this.s.cmdCopied = false;
                    if ((this.s.modal === 'bridge' || this.s.modal === 'manage') && this.s.bridgeCommand) this.renderAll();
                }, 2000);
            }
        }
        async createByok() {
            await this.apiCall('/connections', {
                method: 'POST',
                body: JSON.stringify({ type: 'byok', name: this.s.form.name || null, endpoint: this.s.form.endpoint, api_key: this.s.form.apiKey }),
            });
            this.s.modal = null;
            await this.loadConnections();
            this.renderAll();
        }

        // ── streaming ─────────────────────────────────────────────────
        async send(text) {
            text = (text || '').trim();
            if (this.s.streaming || !text || !this.s.activeId) return;
            this.s.error = null;
            this.s.messages.push({ role: 'user', blocks: [{ type: 'text', text }] });
            this.assistant = { role: 'assistant', blocks: [] };
            this.s.messages.push(this.assistant);
            this.current = null;
            this.s.streaming = true;
            this.s.pulse = true;
            this.renderAll();
            // Backstop: if nothing is heard back at all (a hung POST, a lost
            // broadcast, Reverb misconfigured) the UI must not sit on
            // "Thinking" forever. armWatchdog() ends the turn with an error;
            // every received event resets it (see handleEvent).
            // The FIRST event gets a wider budget — a cold CLI start, a fresh
            // session seeded with long history, or a transparent session_lost
            // recovery can all delay it well past the steady-state timeout.
            this.armWatchdog(this.WATCHDOG_FIRST_MS);

            try {
                const res = await fetch(this.api + '/conversations/' + this.s.activeId + '/stream', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: text }),
                });
                const ct = res.headers.get('content-type') || '';
                if (ct.includes('application/json')) {
                    const d = await res.json();
                    if (d.error) throw new Error(d.message || d.error);
                    // Bridge mode: events arrive over Reverb. The channel was
                    // already subscribed on conversation open; re-subscribing
                    // here is idempotent and covers an older open path.
                    const ok = await this.subscribeConversation(d.channel);
                    if (!ok) { this.s.error = 'Reverb is not configured for this app.'; this.finish(); }
                } else {
                    await this.readSse(res);
                }
            } catch (e) { this.s.error = e.message; this.finish(); }
        }

        // ── watchdog: never let the UI hang on "Thinking" ─────────────
        // A turn can stall with no terminal event — a dropped Reverb
        // broadcast, an event broadcast before the browser subscribed, a
        // misconfigured broadcaster. The watchdog ends such a turn: it shows
        // an error and reloads the conversation (the assistant reply may have
        // been persisted server-side even though its events never arrived).
        armWatchdog(ms) {
            this.clearWatchdog();
            this._watchdog = setTimeout(() => {
                if (!this.s.streaming) return;
                this.s.error = 'No response received — the turn timed out. '
                    + 'The bridge or its connection may be down. '
                    + 'Re-open the conversation to refresh it.';
                this.finish();
            }, ms || this.WATCHDOG_STEADY_MS);
        }
        clearWatchdog() {
            if (this._watchdog) { clearTimeout(this._watchdog); this._watchdog = null; }
        }

        async readSse(res) {
            const reader = res.body.getReader();
            const dec = new TextDecoder();
            let buf = '';
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                buf += dec.decode(value, { stream: true });
                const lines = buf.split('\n');
                buf = lines.pop() || '';
                for (const line of lines) {
                    const tl = line.trim();
                    if (!tl.startsWith('data: ')) continue;
                    const p = tl.slice(6);
                    if (p === '[DONE]') { this.finish(); return; }
                    try { this.handleEvent(JSON.parse(p)); } catch (e) {}
                }
            }
            this.finish();
        }

        // Lazily create the one shared Echo instance, reused by both the
        // connection-status channels and the per-conversation stream channel.
        // Resolves to null when Reverb is not configured for this app.
        async getEcho() {
            if (this.echo) return this.echo;
            if (!this.reverb.key) return null;
            if (!this._echoPromise) {
                this._echoPromise = (async () => {
                    const Echo = await this.loadEcho();
                    this.echo = new Echo({
                        broadcaster: 'reverb', key: this.reverb.key,
                        wsHost: this.reverb.host, wsPort: Number(this.reverb.port), wssPort: Number(this.reverb.port),
                        forceTLS: this.reverb.scheme === 'https', enabledTransports: ['ws', 'wss'],
                        // Package-owned auth route — authorizes via the project's
                        // resolvers, so it works without Laravel authentication.
                        authEndpoint: this.api + '/broadcasting/auth',
                    });
                    return this.echo;
                })();
            }
            return this._echoPromise;
        }

        // Subscribe to a conversation's stream channel for `.ai.stream` events.
        // Idempotent: subscribing the already-subscribed channel is a no-op, so
        // it is safe to call both on conversation open and again on send.
        // Resolves to false when Reverb is not configured for this app.
        async subscribeConversation(channel) {
            if (!channel) return false;
            const echo = await this.getEcho().catch(() => null);
            if (!echo) return false;
            if (this._streamChannel === channel) return true;
            // Leave the previous conversation's channel before joining a new one.
            if (this._streamChannel) {
                try { echo.leave(this._streamChannel); } catch (e) {}
            }
            this._streamChannel = channel;
            echo.private(channel).listen('.ai.stream', (e) => this.handleEvent(e));
            return true;
        }

        /** Load vendored pusher + echo without touching the host's globals. */
        async loadEcho() {
            if (this._EchoClass) return this._EchoClass;
            const hostPusher = window.Pusher, hostEcho = window.Echo;
            await this.loadScript(this.assets + '/pusher.min.js');
            await this.loadScript(this.assets + '/echo.iife.js');
            // The IIFE/UMD bundles expose a module namespace ({ default: Class })
            // on the global, not the class itself — unwrap `.default` so
            // `new Echo(...)` doesn't throw "Echo is not a constructor".
            this._EchoClass = (window.Echo && window.Echo.default) || window.Echo;
            this._PusherClass = (window.Pusher && window.Pusher.default) || window.Pusher;
            window.Pusher = this._PusherClass; // echo needs Pusher global at construct time
            // restore the host's references after capturing ours
            this._restoreGlobals = () => { window.Pusher = hostPusher; window.Echo = hostEcho; };
            return this._EchoClass;
        }
        loadScript(src) {
            return new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = src; s.onload = resolve; s.onerror = () => reject(new Error('Failed to load ' + src));
                document.head.appendChild(s);
            });
        }

        handleEvent(evt) {
            // Any event means the turn is alive — push the watchdog back.
            if (this.s.streaming) this.armWatchdog();
            const d = evt.data || {};
            switch (evt.event) {
                case 'block_start':
                    // Show the "Thinking" pulse while a block is forming — it is
                    // cleared by the first delta (content arriving) or by the
                    // block_stop. Showing it on stop instead caused a flash on
                    // the final block: stop turned it on, done turned it off.
                    this.s.pulse = true;
                    this.current = { type: d.block_type || 'text', text: '', _open: false };
                    this.assistant.blocks.push(this.current);
                    break;
                case 'block_delta':
                    this.s.pulse = false;
                    if (this.current) this.current.text += (d.content || '');
                    break;
                case 'block_stop':
                    this.s.pulse = false; this.current = null;
                    break;
                case 'tool_call':
                    this.assistant.blocks.push({ type: 'tool_call', tool_name: d.tool_name, parameters: d.parameters || {} });
                    break;
                case 'done': this.finish(); return;
                case 'error': this.s.error = d.message || d.code || 'Stream error'; this.finish(); return;
                case 'cancelled': this.finish(); return;
            }
            this.renderAll();
        }
        finish() {
            this.clearWatchdog();
            this.s.streaming = false; this.s.pulse = false; this.current = null;
            this.loadConversations().then(() => this.renderAll());
            this.renderAll();
        }

        scrollDown() {
            const m = this.root.querySelector('.messages');
            if (m) m.scrollTop = m.scrollHeight;
            // (re)bind inputs after each full render
            this.bindFormInputs();
        }
    }

    if (!customElements.get('ai-bridge-chat')) {
        customElements.define('ai-bridge-chat', AiBridgeChat);
    }
})();
