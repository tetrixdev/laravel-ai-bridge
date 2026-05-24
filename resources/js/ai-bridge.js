/**
 * AiBridgeStream — lightweight client for Laravel AI Bridge.
 *
 * A small helper around the package's HTTP endpoints. Apps that build their
 * own UI use this to get the same refresh-safe streaming behaviour the
 * bundled <ai-bridge-chat> component uses.
 *
 * Two modes:
 *
 *   buffered (default — recommended)
 *     For conversation-based streams. POSTs to /conversations/{id}/stream,
 *     then tails /streams/{request_id}/events via native EventSource.
 *     EventSource reconnection with Last-Event-ID makes refresh and tab
 *     switches recover the in-flight reply for free.
 *
 *     const stream = new AiBridgeStream({ mode: 'buffered', api: '/ai-bridge' });
 *     stream.on('text', (content) => ...);
 *     stream.on('done', (usage) => ...);
 *     stream.send({ conversationId: 42, message: 'Hello' });
 *
 *     To re-attach an existing in-flight turn (e.g. on page load):
 *     stream.attach('req-abc123');
 *
 *   sse (direct, no conversation row)
 *     For one-shot streaming against /stream/sse. No resumption — a refresh
 *     loses the response. Use buffered mode if you need recovery.
 *
 *     const stream = new AiBridgeStream({ mode: 'sse', url: '/ai-bridge/stream/sse' });
 *     stream.send({ message: 'Hello', conversation_id: 'temp-123' });
 *
 * Events:
 *   conversation_id, block_start, block_delta, block_stop, text,
 *   thinking, tool_call, tool_result, done, error, cancelled, raw
 *
 *   Both 'done' and 'cancelled' (and 'error') are terminal. Always listen
 *   for all three so your UI cleanup runs regardless of how the turn ends.
 */
class AiBridgeStream {
    constructor(config = {}) {
        this.mode = config.mode || 'buffered';
        this.api = config.api || '/ai-bridge';
        this.url = config.url || (this.api + '/stream/sse');
        this.headers = config.headers || {};
        this.listeners = {};
        this._abortController = null;
        this._eventSource = null;
        this._requestId = null;
    }

    on(event, callback) {
        (this.listeners[event] = this.listeners[event] || []).push(callback);
        return this;
    }

    off(event, callback) {
        if (this.listeners[event]) {
            this.listeners[event] = this.listeners[event].filter((c) => c !== callback);
        }
        return this;
    }

    /**
     * Send a message and start streaming the response.
     *
     * In `buffered` mode, `data` must include `conversationId` and `message`.
     * In `sse` mode, `data` is forwarded to /stream/sse verbatim (typical
     * keys: message, conversation_id, system_prompt, options).
     */
    async send(data) {
        if (this.mode === 'buffered') {
            return this._sendBuffered(data);
        }
        return this._sendSse(data);
    }

    /**
     * Re-attach to an already-running turn by its request_id.
     *
     * Replays the buffered events from the start of the turn, then tails any
     * new ones. The EventSource itself handles reconnect/resumption from
     * that point on. Use on page load when /conversations/{id} returns a
     * non-null streaming_request_id.
     */
    attach(requestId) {
        this._attachEventSource(requestId, /* fromIndex = */ -1);
    }

    /** Cancel the in-flight turn (server-side flag, observed by the serve process). */
    async abort() {
        if (this._abortController) {
            try { this._abortController.abort(); } catch (e) {}
            this._abortController = null;
        }
        const rid = this._requestId;
        if (!rid) return;
        try {
            await fetch(this.api + '/streams/' + encodeURIComponent(rid) + '/abort', {
                method: 'POST', credentials: 'same-origin',
                headers: Object.assign({ 'Content-Type': 'application/json' }, this.headers),
            });
        } catch (e) { /* the EventSource terminal will land regardless */ }
    }

    close() {
        if (this._eventSource) {
            try { this._eventSource.close(); } catch (e) {}
            this._eventSource = null;
        }
        if (this._abortController) {
            try { this._abortController.abort(); } catch (e) {}
            this._abortController = null;
        }
        this._requestId = null;
    }

    // ── internals ────────────────────────────────────────────────────

    async _sendBuffered(data) {
        const conversationId = data.conversationId ?? data.conversation_id;
        const message = data.message;
        if (!conversationId) throw new Error('AiBridgeStream(buffered): conversationId is required.');
        if (!message) throw new Error('AiBridgeStream(buffered): message is required.');

        let res;
        try {
            res = await fetch(this.api + '/conversations/' + encodeURIComponent(conversationId) + '/stream', {
                method: 'POST', credentials: 'same-origin',
                headers: Object.assign({ 'Accept': 'application/json', 'Content-Type': 'application/json' }, this.headers),
                body: JSON.stringify({ message }),
            });
        } catch (e) {
            this._emit('error', 'network_error', e.message);
            return;
        }
        if (!res.ok) {
            let msg = 'HTTP ' + res.status;
            try { const j = await res.json(); msg = j.message || j.error || msg; } catch (e) {}
            this._emit('error', 'http_error', msg);
            return;
        }
        const j = await res.json();
        if (!j.request_id) {
            this._emit('error', 'invalid_response', 'Server did not return a request_id.');
            return;
        }
        this._attachEventSource(j.request_id, /* fromIndex = */ -1);
    }

    _attachEventSource(requestId, fromIndex) {
        this.close();
        this._requestId = requestId;
        const url = this.api + '/streams/' + encodeURIComponent(requestId)
            + '/events' + (fromIndex >= 0 ? ('?from_index=' + fromIndex) : '');
        const es = new EventSource(url, { withCredentials: true });
        this._eventSource = es;
        const wire = (name) => es.addEventListener(name, (ev) => {
            let d = {};
            try { d = JSON.parse(ev.data || '{}'); } catch (e) {}
            this._dispatch(name, d);
        });
        ['block_start', 'block_delta', 'block_stop', 'tool_call', 'tool_result',
         'done', 'error', 'cancelled', 'conversation_id'].forEach(wire);
        es.onerror = () => {
            if (es.readyState === 2 /* CLOSED */) {
                this._emit('error', 'stream_closed',
                    'Stream connection closed unexpectedly. Refresh to recover.');
                this.close();
            }
        };
    }

    async _sendSse(data) {
        this.close();
        this._abortController = new AbortController();
        let res;
        try {
            res = await fetch(this.url, {
                method: 'POST', credentials: 'same-origin',
                headers: Object.assign({ 'Accept': 'text/event-stream', 'Content-Type': 'application/json' }, this.headers),
                body: JSON.stringify(data),
                signal: this._abortController.signal,
            });
        } catch (e) {
            this._emit('error', 'network_error', e.message);
            return;
        }
        if (!res.ok || !res.body) {
            this._emit('error', 'http_error', 'HTTP ' + res.status);
            return;
        }
        const reader = res.body.getReader();
        const dec = new TextDecoder();
        let buf = '';
        try {
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
                    if (p === '[DONE]') return;
                    try {
                        const payload = JSON.parse(p);
                        this._dispatch(payload.event || 'raw', payload.data || {});
                    } catch (e) {}
                }
            }
        } catch (e) {
            this._emit('error', 'read_failed', e.message);
        }
    }

    _dispatch(event, data) {
        this._emit('raw', { event, data });
        switch (event) {
            case 'block_start':
                this._emit('block_start', data);
                break;
            case 'block_delta':
                this._emit('block_delta', data);
                if ((data.block_type || 'text') === 'thinking') {
                    this._emit('thinking', data.content || '');
                } else {
                    this._emit('text', data.content || '');
                }
                break;
            case 'block_stop':
                this._emit('block_stop', data);
                break;
            case 'tool_call':
                this._emit('tool_call', data);
                break;
            case 'tool_result':
                this._emit('tool_result', data);
                break;
            case 'conversation_id':
                this._emit('conversation_id', data.conversation_id || '');
                break;
            case 'done':
                this._emit('done', data.usage || null);
                this.close();
                break;
            case 'error':
                this._emit('error', data.code || 'unknown', data.message || 'Unknown error');
                this.close();
                break;
            case 'cancelled':
                this._emit('cancelled', data.reason || 'Cancelled.');
                this.close();
                break;
        }
    }

    _emit(event, ...args) {
        (this.listeners[event] || []).forEach((cb) => {
            try { cb(...args); } catch (e) {}
        });
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = AiBridgeStream;
} else if (typeof window !== 'undefined') {
    window.AiBridgeStream = AiBridgeStream;
}
