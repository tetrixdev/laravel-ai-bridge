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
    .conn { border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; padding: 8px; font-size: 11px; margin-top: 8px; }
    .conn b { display: block; }
    .conn span { color: #6b7280; }
    .main { flex: 1; display: flex; flex-direction: column; }
    .placeholder { flex: 1; display: flex; align-items: center; justify-content: center; color: #9ca3af; }
    .header { display: flex; align-items: center; justify-content: space-between;
        border-bottom: 1px solid #e5e7eb; padding: 10px 16px; }
    .header .name { font-size: 14px; font-weight: 500; }
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
        align-items: center; justify-content: center; padding: 16px; z-index: 50; }
    .modal { background: #fff; border-radius: 12px; padding: 20px; width: 100%; max-width: 420px; }
    .modal h3 { margin: 0 0 12px; font-size: 16px; }
    .modal label { display: block; font-size: 12px; font-weight: 500; color: #4b5563; margin-bottom: 4px; }
    .modal select, .modal input, .modal textarea { width: 100%; margin-bottom: 12px; }
    .modal .actions { display: flex; justify-content: flex-end; gap: 8px; }
    .cmd { background: #111827; color: #f3f4f6; border-radius: 8px; padding: 12px;
        font-size: 11px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
    .hidden { display: none !important; }
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
                modal: null, bridgeCommand: null, streaming: false, error: null,
                pulse: false, form: {},
            };

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
            this.container.innerHTML =
                `<div class="root">${this.sidebarHtml()}${this.mainHtml()}</div>${this.modalHtml()}`;
            this.scrollDown();
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
                return `<div class="conn"><b>${this.esc(cn.name || cn.type)}</b><span>${this.esc(provs)}</span></div>`;
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
            if (!this.s.conv) return `<div class="main"><div class="placeholder">Select or start a conversation.</div></div>`;
            const c = this.s.conv;
            const msgs = this.s.messages.map((m, mi) => this.msgHtml(m, mi)).join('');
            return `
            <div class="main">
                <div class="header">
                    <div class="name">${this.esc(c.title || 'Conversation #' + c.id)}</div>
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
                    ? `<h3>Add a CLI bridge</h3><p style="font-size:12px;color:#4b5563">Run this where your Codex / Claude / Gemini CLI is installed:</p>
                       <div class="cmd">${this.esc(this.s.bridgeCommand)}</div>
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
            }
            return `<div class="overlay" data-act="overlay"><div class="modal">${body}</div></div>`;
        }

        // ── events ────────────────────────────────────────────────────
        onClick(e) {
            const t = e.target.closest('[data-act]');
            if (!t) return;
            const act = t.dataset.act;
            const id = t.dataset.id;
            if (act === 'open') this.openConversation(Number(id));
            else if (act === 'del') { e.stopPropagation(); this.deleteConversation(Number(id)); }
            else if (act === 'newchat') this.openModal('chat');
            else if (act === 'togglesetup') { this.s.setupOpen = !this.s.setupOpen; this.renderAll(); }
            else if (act === 'addbridge') { this.s.form = {}; this.s.bridgeCommand = null; this.openModal('bridge'); }
            else if (act === 'addbyok') { this.s.form = {}; this.openModal('byok'); }
            else if (act === 'closemodal' || act === 'overlay') { if (act === 'overlay' && e.target !== t) return; this.s.modal = null; this.renderAll(); }
            else if (act === 'createchat') this.createConversation();
            else if (act === 'createbridge') this.createBridge();
            else if (act === 'createbyok') this.createByok();
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
            const form = this.root.querySelector('[data-act="sendform"]');
            if (form) form.addEventListener('submit', (e) => { e.preventDefault(); this.send(form.draft.value); });
        }
        refreshModalButtons() { /* lightweight: re-render modal only */ this.renderAll(); }

        openModal(kind) { this.s.modal = kind; this.renderAll(); }
        selectedConnection() {
            return this.s.connections.find((c) => String(c.id) === String(this.s.form.connectionId)) || null;
        }

        // ── conversations ─────────────────────────────────────────────
        async openConversation(id) {
            this.s.activeId = id; this.s.error = null;
            try {
                const d = await this.apiCall('/conversations/' + id);
                this.s.conv = d.conversation;
                this.s.toolsStale = !!d.tools_stale;
                this.s.messages = (d.messages || []).map((m) => ({
                    role: m.role,
                    blocks: (Array.isArray(m.blocks) && m.blocks.length)
                        ? m.blocks.map((b) => ({ ...b, _open: false }))
                        : [{ type: 'text', text: m.content || '' }],
                }));
            } catch (e) { this.s.error = e.message; }
            this.renderAll();
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
            const d = await this.apiCall('/connections', {
                method: 'POST', body: JSON.stringify({ type: 'bridge', name: this.s.form.name || null }),
            });
            this.s.bridgeCommand = d.command;
            await this.loadConnections();
            this.renderAll();
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
                    await this.listenReverb(d.channel);
                } else {
                    await this.readSse(res);
                }
            } catch (e) { this.s.error = e.message; this.finish(); }
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

        async listenReverb(channel) {
            if (!this.reverb.key) { this.s.error = 'Reverb is not configured for this app.'; this.finish(); return; }
            if (!this.echo) {
                const Echo = await this.loadEcho();
                this.echo = new Echo({
                    broadcaster: 'reverb', key: this.reverb.key,
                    wsHost: this.reverb.host, wsPort: Number(this.reverb.port), wssPort: Number(this.reverb.port),
                    forceTLS: this.reverb.scheme === 'https', enabledTransports: ['ws', 'wss'],
                    authEndpoint: '/broadcasting/auth',
                });
            }
            this.echo.private(channel).listen('.ai.stream', (e) => this.handleEvent(e));
        }

        /** Load vendored pusher + echo without touching the host's globals. */
        async loadEcho() {
            if (this._EchoClass) return this._EchoClass;
            const hostPusher = window.Pusher, hostEcho = window.Echo;
            await this.loadScript(this.assets + '/pusher.min.js');
            await this.loadScript(this.assets + '/echo.iife.js');
            this._EchoClass = window.Echo;
            this._PusherClass = window.Pusher;
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
            const d = evt.data || {};
            switch (evt.event) {
                case 'block_start':
                    this.current = { type: d.block_type || 'text', text: '', _open: false };
                    this.assistant.blocks.push(this.current);
                    break;
                case 'block_delta':
                    this.s.pulse = false;
                    if (this.current) this.current.text += (d.content || '');
                    break;
                case 'block_stop':
                    this.s.pulse = true; this.current = null;
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
